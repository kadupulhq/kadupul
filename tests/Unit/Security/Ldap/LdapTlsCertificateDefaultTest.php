<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The TLS Certificate Requirements default stays at Never, as in 1.2.31, so
 * installs without a saved row keep connecting. When encryption is on and the
 * requirement resolves to Never, Ldap::Connect() logs a warning that the
 * server certificate is not verified.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function ldap_tls_probe_source() : string {
	return <<<'PHP'
<?php
namespace {
	define('POLLER_VERBOSITY_NONE', 1);
	define('POLLER_VERBOSITY_LOW', 2);
	define('POLLER_VERBOSITY_MEDIUM', 3);
	define('POLLER_VERBOSITY_HIGH', 4);
	define('POLLER_VERBOSITY_DEBUG', 5);
	define('POLLER_VERBOSITY_DEVDBG', 6);

	$GLOBALS['scenario'] = json_decode(stream_get_contents(STDIN), true);
	$GLOBALS['logs']     = array();
	$GLOBALS['options']  = array();

	function read_config_option($name, $force = false) {
		return $GLOBALS['scenario']['config'][$name] ?? '';
	}

	function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
		$GLOBALS['logs'][] = array($string, $level);
	}

	function get_selective_log_level() {
		return POLLER_VERBOSITY_NONE;
	}

	function cacti_debug_backtrace($entry = '', $html = false, $record = true, $limit = 0, $skip = 0) {
		return '';
	}

	function __($text, ...$args) {
		return vsprintf($text, $args);
	}

	function cacti_sizeof($array) {
		return is_array($array) ? count($array) : 0;
	}

	function cacti_session_close() {
	}

	function cacti_session_start($regenerate = false) {
	}

	function CactiErrorHandler($level, $message, $file, $line) {
		return true;
	}
}

namespace LdapTlsProbe {
	function ldap_connect($host = null, $port = 389) {
		return 'probe-connection';
	}

	function ldap_set_option($conn, $option, $value) {
		if ($conn === null) {
			$GLOBALS['options'][] = array($option, $value);
		}

		return true;
	}

	function ldap_start_tls($conn) {
		return true;
	}

	function ldap_close($conn) {
		return true;
	}

	function ldap_bind($conn, $dn = null, $password = null) {
		return true;
	}

	function ldap_errno($conn) {
		return 0;
	}

	function ldap_error($conn) {
		return '';
	}
}

namespace {
	// nosemgrep: php.lang.security.eval-use.eval-use -- test-only evaluation of a repository-owned file
	eval('namespace LdapTlsProbe; ' . preg_replace('/^<\?php\s*/', '', $GLOBALS['scenario']['source']));

	/* two connections in one request, as a login followed by a CN lookup makes */
	for ($i = 0; $i < 2; $i++) {
		$ldap = new \LdapTlsProbe\Ldap();
		$ldap->username = 'alice';
		$ldap->password = 'secret';
		$ldap->Authenticate();
	}

	$warnings = array_values(array_filter($GLOBALS['logs'], function (array $log) : bool {
		return strpos($log[0], 'WARNING') === 0;
	}));

	print json_encode(array(
		'warnings' => $warnings,
		'options'  => $GLOBALS['options'],
	));
}
PHP;
}

function ldap_tls_release_source(string $path) : ?string {
	static $sources = array();

	if (!array_key_exists($path, $sources)) {
		$output = shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 4)) . ' show release/1.2.31:' . escapeshellarg($path) . ' 2>/dev/null');
		$sources[$path] = (is_string($output) && $output !== '') ? $output : null;
	}

	return $sources[$path];
}

/**
 * @return array<string, mixed>
 */
function ldap_tls_run(string $encryption, string $certificate, ?string $source = null) : array {
	return cacti_test_run_php_source(ldap_tls_probe_source(), array(
		'source' => $source ?? file_get_contents(dirname(__DIR__, 4) . '/lib/ldap.php'),
		'config' => array(
			'ldap_dn'              => 'uid=<username>,ou=people,dc=example,dc=com',
			'ldap_server'          => 'ldap.example.com',
			'ldap_port'            => '389',
			'ldap_port_ssl'        => '636',
			'ldap_version'         => '3',
			'ldap_encryption'      => $encryption,
			'ldap_tls_certificate' => $certificate,
		),
	));
}

function ldap_tls_settings_entry(string $source) : string {
	if (preg_match("/'ldap_tls_certificate'\s*=>\s*array\(.*?'array'\s*=>\s*\\\$ldap_tls_cert_req\s*\)/s", $source, $match) !== 1) {
		throw new RuntimeException('ldap_tls_certificate setting not found');
	}

	return preg_replace('/\s+/', ' ', $match[0]);
}

test('the TLS certificate requirement defaults to Never', function () {
	$entry = ldap_tls_settings_entry(file_get_contents(dirname(__DIR__, 4) . '/include/global_settings.php'));

	expect($entry)->toContain("'default' => LDAP_OPT_X_TLS_NEVER,")
		->and($entry)->not->toContain('LDAP_OPT_X_TLS_DEMAND');
});

test('the TLS certificate setting matches 1.2.31', function () {
	expect(ldap_tls_settings_entry(file_get_contents(dirname(__DIR__, 4) . '/include/global_settings.php')))
		->toBe(ldap_tls_settings_entry(ldap_tls_release_source('include/global_settings.php')));
})->skip(function () {
	return ldap_tls_release_source('include/global_settings.php') === null;
}, 'release/1.2.31 is not available in this clone');

test('encryption with an unverified server certificate logs one warning per request', function () {
	foreach (array('1', '2') as $encryption) {
		foreach (array('', (string) LDAP_OPT_X_TLS_NEVER) as $certificate) {
			$result = ldap_tls_run($encryption, $certificate);

			expect($result['warnings'])->toHaveCount(1)
				->and($result['warnings'][0][0])->toContain('not verified')
				->and($result['warnings'][0][1])->toBe('');
		}
	}
});

test('no warning when the certificate is checked or LDAP is not encrypted', function () {
	foreach (array(LDAP_OPT_X_TLS_DEMAND, LDAP_OPT_X_TLS_HARD, LDAP_OPT_X_TLS_TRY, LDAP_OPT_X_TLS_ALLOW) as $certificate) {
		expect(ldap_tls_run('1', (string) $certificate)['warnings'])->toBe(array());
	}

	expect(ldap_tls_run('0', (string) LDAP_OPT_X_TLS_NEVER)['warnings'])->toBe(array());
});

test('the certificate requirement handed to php-ldap matches 1.2.31', function () {
	$source = ldap_tls_release_source('lib/ldap.php');

	foreach (array('1', '2') as $encryption) {
		foreach (array('', (string) LDAP_OPT_X_TLS_NEVER, (string) LDAP_OPT_X_TLS_DEMAND, (string) LDAP_OPT_X_TLS_HARD) as $certificate) {
			expect(ldap_tls_run($encryption, $certificate)['options'])
				->toBe(ldap_tls_run($encryption, $certificate, $source)['options'], "encryption $encryption, certificate '$certificate'");
		}
	}
})->skip(function () {
	return ldap_tls_release_source('lib/ldap.php') === null;
}, 'release/1.2.31 is not available in this clone');

/**
 * Run prime_default_settings(), and for an upgrade upgrade_to_1_2_32(), against
 * an in-memory settings table whose name column is the primary key, so INSERT
 * IGNORE never replaces a stored row. install/install.php primes settings; a
 * CLI upgrade runs only the upgrade step.
 *
 * @param array<string, string> $stored
 *
 * @return array<string, string>
 */
function ldap_tls_install_run(string $version, array $stored, bool $prime, bool $upgrade, ?string $install_functions = null) : array {
	$root = dirname(__DIR__, 4);
	$lib  = file_get_contents($root . '/lib/functions.php');

	$code  = cacti_test_function_source($install_functions ?? file_get_contents($root . '/install/functions.php'), 'prime_default_settings') . "\n\n";
	$code .= cacti_test_function_source($lib, 'cacti_version_compare') . "\n\n";
	$code .= cacti_test_function_source($lib, 'version_to_decimal') . "\n\n";
	$code .= cacti_test_function_source(file_get_contents($root . '/install/upgrades/1_2_32.php'), 'upgrade_to_1_2_32') . "\n\n";

	$child = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

$GLOBALS['table'] = $scenario['stored'];
$_SESSION         = array();

$settings = array(
	'authentication' => array(
		'ldap_tls_certificate' => array('method' => 'drop_array', 'default' => $scenario['default']),
	),
);

function insert_ignore($name, $value) {
	if (!array_key_exists($name, $GLOBALS['table'])) {
		$GLOBALS['table'][$name] = (string) $value;
	}
}

function get_cacti_version() {
	return $GLOBALS['scenario']['version'];
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function db_fetch_cell_prepared($sql, $params = array(), $col_name = '', $log = true) {
	return array_key_exists($params[0], $GLOBALS['table']) ? $GLOBALS['table'][$params[0]] : false;
}

function db_execute_prepared($sql, $params = array(), $log = true) {
	if (strpos($sql, 'INSERT IGNORE INTO settings') !== false) {
		insert_ignore($params[0], $params[1]);
	}

	return true;
}

function db_install_execute($sql, $params = array(), $log = true) {
	if (preg_match("/^INSERT IGNORE INTO settings \(name, value\) VALUES \('([^']+)', '([^']*)'\)$/", $sql, $match)) {
		insert_ignore($match[1], $match[2]);
	}

	return true;
}

function db_table_exists($table, $log = true, $db_conn = false) {
	return true;
}

function db_column_exists($table, $column, $log = true, $db_conn = false) {
	return true;
}

function db_install_add_column($table, $column, $log = true) {
	return true;
}

function db_install_add_key($table, $type, $key, $columns, $using = '') {
	return true;
}

PHP;

	$child .= $code;
	$child .= <<<'PHP'
if ($scenario['prime']) {
	prime_default_settings();
}

if ($scenario['upgrade']) {
	upgrade_to_1_2_32();
}

print json_encode(array('table' => $GLOBALS['table']));
PHP;

	return cacti_test_run_php_source($child, array(
		'version' => $version,
		'stored'  => (object) $stored,
		'prime'   => $prime,
		'upgrade' => $upgrade,
		'default' => constant(ldap_tls_shipped_default()),
	))['table'];
}

function ldap_tls_shipped_default() : string {
	$entry = ldap_tls_settings_entry(file_get_contents(dirname(__DIR__, 4) . '/include/global_settings.php'));

	if (preg_match("/'default' => (LDAP_OPT_X_TLS_[A-Z]+),/", $entry, $match) !== 1) {
		throw new RuntimeException('ldap_tls_certificate default not found');
	}

	return $match[1];
}

test('a new web install stores Demand for LDAP TLS certificates', function () {
	expect(ldap_tls_install_run('new_install', array(), true, false))
		->toHaveKey('ldap_tls_certificate', (string) LDAP_OPT_X_TLS_DEMAND);
});

test('a CLI upgrade without a stored row saves no LDAP TLS certificate value', function () {
	foreach (array('1.2.31', '1.2.27') as $version) {
		expect(ldap_tls_install_run($version, array(), false, true))->not->toHaveKey('ldap_tls_certificate');
	}
});

test('a web upgrade stores the Never default that 1.2.31 priming stored', function () {
	foreach (array('1.2.31', '1.2.27', '1.2.32') as $version) {
		expect(ldap_tls_install_run($version, array(), true, true))
			->toHaveKey('ldap_tls_certificate', (string) LDAP_OPT_X_TLS_NEVER);
	}
});

test('a stored LDAP TLS certificate value survives every install and upgrade path', function () {
	foreach (array((string) LDAP_OPT_X_TLS_NEVER, (string) LDAP_OPT_X_TLS_ALLOW) as $value) {
		foreach (array(array('new_install', true, false), array('1.2.31', true, true), array('1.2.31', false, true)) as $path) {
			expect(ldap_tls_install_run($path[0], array('ldap_tls_certificate' => $value), $path[1], $path[2]))
				->toHaveKey('ldap_tls_certificate', $value);
		}
	}
});

test('priming an existing install stores what 1.2.31 priming stored', function () {
	$release = ldap_tls_release_source('install/functions.php');

	foreach (array('1.2.31', '1.2.27') as $version) {
		$current = ldap_tls_install_run($version, array(), true, false);
		$before  = ldap_tls_install_run($version, array(), true, false, $release);

		expect($current['ldap_tls_certificate'])->toBe($before['ldap_tls_certificate']);
	}
})->skip(function () {
	return ldap_tls_release_source('install/functions.php') === null;
}, 'release/1.2.31 is not available in this clone');