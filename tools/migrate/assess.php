#!/usr/bin/env php
<?php
/**
 * Reports what an existing installation contains and what would survive a migration to
 * Kadupul.
 *
 * Read only. It opens the database, runs SELECT statements, and stats files on
 * disk. It never writes, so it is safe to point at production. That property is
 * the whole point: an operator will run an assessment against a live system,
 * and will not run anything else until they have read its output.
 *
 * Usage:
 *   assess.php --config=/var/www/html/cacti/include/config.php [--json]
 *   assess.php --dsn=mysql:host=db;dbname=cacti --user=cactiuser --password=... [--json]
 */

if (PHP_SAPI !== 'cli') {
	if (!headers_sent()) {
		http_response_code(404);
	}
	exit;
}

const SUPPORTED_FROM = '1.2.0';

/** Poller scratch space. Rebuilt on the next cycle, so it is never migrated. */
const TRANSIENT_TABLES = [
	'poller_output',
	'poller_output_boost',
	'poller_output_boost_local_data_ids',
	'poller_output_boost_processes',
	'poller_output_realtime',
	'poller_item',
	'poller_time',
	'host_snmp_cache',
];

function fail(string $message) : never {
	fwrite(STDERR, 'error: ' . $message . "\n");
	exit(1);
}

/**
 * Pulls the database settings out of the source config.php without executing the
 * rest of it. The file defines paths and includes others, and running it in
 * this process would drag in an entire installation.
 *
 * @return array{dsn: string, user: string, password: string, path: string}
 */
function read_cacti_config(string $file) : array {
	if (!is_readable($file)) {
		fail("cannot read $file");
	}

	$source = file_get_contents($file);
	$want   = ['database_type', 'database_default', 'database_hostname', 'database_username', 'database_password', 'database_port'];
	$found  = [];

	foreach ($want as $name) {
		if (preg_match('/^\s*\$' . $name . '\s*=\s*([\'"])(.*?)\1\s*;/m', $source, $m)) {
			$found[$name] = $m[2];
		}
	}

	foreach (['database_default', 'database_hostname', 'database_username'] as $required) {
		if (!isset($found[$required])) {
			fail("$file does not define \$$required");
		}
	}

	$driver = ($found['database_type'] ?? 'mysql') === 'mysqli' ? 'mysql' : ($found['database_type'] ?? 'mysql');
	$port   = $found['database_port'] ?? '3306';

	return [
		'dsn'      => sprintf('%s:host=%s;port=%s;dbname=%s;charset=utf8mb4', $driver, $found['database_hostname'], $port, $found['database_default']),
		'user'     => $found['database_username'],
		'password' => $found['database_password'] ?? '',
		'path'     => dirname($file, 2),
	];
}

function connect(string $dsn, string $user, string $password) : PDO {
	try {
		return new PDO($dsn, $user, $password, [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
	} catch (PDOException $e) {
		fail('cannot connect: ' . $e->getMessage());
	}
}

function scalar(PDO $db, string $sql, array $args = []) : ?string {
	try {
		$st = $db->prepare($sql);
		$st->execute($args);
		$value = $st->fetchColumn();

		return $value === false ? null : (string) $value;
	} catch (PDOException) {
		// A table this query needs is absent. The caller decides what that means.
		return null;
	}
}

function rows(PDO $db, string $sql, array $args = []) : array {
	try {
		$st = $db->prepare($sql);
		$st->execute($args);

		return $st->fetchAll();
	} catch (PDOException) {
		return [];
	}
}

function assess_version(PDO $db) : array {
	$version = scalar($db, 'SELECT cacti FROM version');

	if ($version === null) {
		return ['version' => null, 'supported' => false, 'note' => 'no version table; this may not be a compatible source database'];
	}

	$supported = version_compare($version, SUPPORTED_FROM, '>=');

	return [
		'version'   => $version,
		'supported' => $supported,
		'note'      => $supported ? null : 'upgrade to ' . SUPPORTED_FROM . ' using the source application before migration',
	];
}

function assess_inventory(PDO $db) : array {
	return [
		'devices'          => (int) scalar($db, 'SELECT COUNT(*) FROM host'),
		'devices_disabled' => (int) scalar($db, "SELECT COUNT(*) FROM host WHERE disabled = 'on'"),
		'data_sources'     => (int) scalar($db, 'SELECT COUNT(*) FROM data_local'),
		'graphs'           => (int) scalar($db, 'SELECT COUNT(*) FROM graph_local'),
		'graph_templates'  => (int) scalar($db, 'SELECT COUNT(*) FROM graph_templates'),
		'data_templates'   => (int) scalar($db, 'SELECT COUNT(*) FROM data_template'),
		'users'            => (int) scalar($db, 'SELECT COUNT(*) FROM user_auth'),
		'realms'           => (int) scalar($db, 'SELECT COUNT(*) FROM user_domains'),
		'remote_pollers'   => (int) scalar($db, 'SELECT COUNT(*) FROM poller WHERE id > 1'),
	];
}

/**
 * Confirms the RRD files the database expects are actually on disk and current.
 * A count alone is not evidence: the common failure is a database that believes
 * in files a filesystem move left behind.
 */
function assess_rrds(PDO $db, ?string $rra_path, int $sample) : array {
	$expected = (int) scalar($db, "SELECT COUNT(*) FROM data_template_data WHERE data_source_path != ''");

	$result = [
		'rra_path'      => $rra_path,
		'expected'      => $expected,
		'checked'       => 0,
		'missing'       => 0,
		'unreadable'    => 0,
		'stale'         => 0,
		'missing_files' => [],
	];

	if ($rra_path === null || !is_dir($rra_path)) {
		$result['note'] = 'rra path not readable from here; run this on the source host for a file check';

		return $result;
	}

	$paths = rows($db, "SELECT data_source_path FROM data_template_data WHERE data_source_path != '' LIMIT " . $sample);
	$cycle = (int) (scalar($db, "SELECT value FROM settings WHERE name = 'poller_interval'") ?? 300);

	foreach ($paths as $row) {
		$file = str_replace('<path_rra>', $rra_path, $row['data_source_path']);
		$result['checked']++;

		if (!file_exists($file)) {
			$result['missing']++;
			if (count($result['missing_files']) < 10) {
				$result['missing_files'][] = $file;
			}
			continue;
		}

		if (!is_readable($file)) {
			$result['unreadable']++;
			continue;
		}

		// Two cycles of slack: one in progress is normal, two is not.
		if (time() - (int) filemtime($file) > $cycle * 2) {
			$result['stale']++;
		}
	}

	return $result;
}

function assess_plugins(PDO $db) : array {
	$plugins = rows($db, 'SELECT directory, name, version, status FROM plugin_config ORDER BY directory');

	foreach ($plugins as &$plugin) {
		$plugin['status_text'] = match ((int) $plugin['status']) {
			0       => 'not installed',
			1       => 'installed, not active',
			4       => 'active',
			default => 'unknown (' . $plugin['status'] . ')',
		};
		// Nothing is vouched for until it has been tested against the fork.
		$plugin['known_working'] = false;
	}

	return $plugins;
}

/** Things that will surprise somebody mid-migration if nobody says them now. */
function assess_anomalies(PDO $db, array $rrds, array $inventory) : array {
	$notes = [];

	if ($inventory['remote_pollers'] > 0) {
		$notes[] = sprintf('%d remote poller(s): each one needs its own migration and its own config', $inventory['remote_pollers']);
	}

	if ($rrds['missing'] > 0) {
		$notes[] = sprintf('%d of %d sampled data sources point at files that are not there', $rrds['missing'], $rrds['checked']);
	}

	if ($rrds['stale'] > 0) {
		$notes[] = sprintf('%d of %d sampled RRD files have not been updated in two poller cycles', $rrds['stale'], $rrds['checked']);
	}

	$orphans = (int) scalar($db, 'SELECT COUNT(*) FROM data_local WHERE host_id NOT IN (SELECT id FROM host)');
	if ($orphans > 0) {
		$notes[] = sprintf('%d data sources reference a device that no longer exists', $orphans);
	}

	$changed = (int) scalar($db, 'SELECT COUNT(*) FROM settings');
	if ($changed > 0) {
		$notes[] = sprintf('%d settings differ from their defaults and must be translated, not copied', $changed);
	}

	foreach (TRANSIENT_TABLES as $table) {
		$count = scalar($db, "SELECT COUNT(*) FROM `$table`");
		if ($count !== null && (int) $count > 1000000) {
			$notes[] = sprintf('%s holds %s rows; it is poller scratch space and is not migrated', $table, number_format((int) $count));
		}
	}

	return $notes;
}

function human(array $report) : void {
	$v = $report['version'];
	print "Source installation\n";
	print '  version        ' . ($v['version'] ?? 'unknown') . ($v['supported'] ? '' : '   NOT SUPPORTED') . "\n";
	if ($v['note'] !== null) {
		print '                 ' . $v['note'] . "\n";
	}

	print "\nInventory\n";
	foreach ($report['inventory'] as $name => $count) {
		printf("  %-16s %s\n", str_replace('_', ' ', $name), number_format($count));
	}

	$r = $report['rrds'];
	print "\nRRD files\n";
	printf("  %-16s %s\n", 'path', $r['rra_path'] ?? 'not checked');
	printf("  %-16s %s\n", 'expected', number_format($r['expected']));
	printf("  %-16s %s\n", 'sampled', number_format($r['checked']));
	printf("  %-16s %s\n", 'missing', number_format($r['missing']));
	printf("  %-16s %s\n", 'unreadable', number_format($r['unreadable']));
	printf("  %-16s %s\n", 'stale', number_format($r['stale']));
	if (isset($r['note'])) {
		print '  ' . $r['note'] . "\n";
	}

	print "\nPlugins\n";
	if ($report['plugins'] === []) {
		print "  none installed\n";
	} else {
		foreach ($report['plugins'] as $p) {
			printf("  %-20s %-10s %s\n", $p['directory'], $p['version'], $p['status_text']);
		}
		print "  none are vouched for yet; each needs testing against the fork\n";
	}

	print "\nWorth knowing\n";
	if ($report['anomalies'] === []) {
		print "  nothing unusual\n";
	} else {
		foreach ($report['anomalies'] as $note) {
			print '  - ' . $note . "\n";
		}
	}
}

// --- main -------------------------------------------------------------------

$opts = getopt('', ['config:', 'dsn:', 'user:', 'password:', 'rra-path:', 'sample:', 'json', 'help']);

if (isset($opts['help']) || ($opts === [] || (!isset($opts['config']) && !isset($opts['dsn'])))) {
	print "usage: assess.php --config=/path/to/cacti/include/config.php [--json]\n";
	print "       assess.php --dsn=DSN --user=USER --password=PASS [--rra-path=DIR] [--json]\n";
	exit(isset($opts['help']) ? 0 : 1);
}

if (isset($opts['config'])) {
	$cfg      = read_cacti_config($opts['config']);
	$dsn      = $cfg['dsn'];
	$user     = $cfg['user'];
	$password = $cfg['password'];
	$install  = $cfg['path'];
} else {
	$dsn      = $opts['dsn'];
	$user     = $opts['user'] ?? '';
	$password = $opts['password'] ?? '';
	$install  = null;
}

$db     = connect($dsn, $user, $password);
$sample = max(1, (int) ($opts['sample'] ?? 500));

$rra_path = $opts['rra-path'] ?? null;
if ($rra_path === null && $install !== null) {
	$setting  = scalar($db, "SELECT value FROM settings WHERE name = 'path_rra'");
	$rra_path = ($setting !== null && $setting !== '') ? $setting : $install . '/rra';
}

$inventory = assess_inventory($db);
$rrds      = assess_rrds($db, $rra_path, $sample);

$report = [
	'assessed_at' => gmdate('c'),
	'version'     => assess_version($db),
	'inventory'   => $inventory,
	'rrds'        => $rrds,
	'plugins'     => assess_plugins($db),
	'anomalies'   => assess_anomalies($db, $rrds, $inventory),
];

if (isset($opts['json'])) {
	print json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
	human($report);
}

exit($report['version']['supported'] ? 0 : 2);
