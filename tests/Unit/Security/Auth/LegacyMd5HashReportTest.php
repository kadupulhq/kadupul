<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Local accounts that nobody has logged into since the upgrade still store an
 * unsalted MD5 password hash. Maintenance reports how many remain and User
 * Management names them. Nothing forces a reset.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function legacy_md5_report_run(array $rows, string $call, bool $debounce = true) : array {
	$root  = dirname(__DIR__, 4);
	$auth  = file_get_contents($root . '/lib/auth.php');
	$admin  = file_get_contents($root . '/user_admin.php');
	$maint  = file_get_contents($root . '/poller_maintenance.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->sqliteCreateFunction('REGEXP', function ($pattern, $value) {
	return preg_match('/' . $pattern . '/', (string) $value);
}, 2);
$pdo->exec('CREATE TABLE user_auth (id INTEGER, username TEXT, realm INTEGER, password TEXT)');

foreach ($scenario['rows'] as $row) {
	$pdo->prepare('INSERT INTO user_auth (id, username, realm, password) VALUES (?, ?, ?, ?)')->execute($row);
}

$GLOBALS['logs'] = array();

function db_fetch_assoc($sql) {
	global $pdo;

	return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function __esc($text, ...$args) {
	return htmlspecialchars(vsprintf($text, $args), ENT_QUOTES);
}

function html_start_box($title, ...$args) {
	print "[box:$title]";
}

function html_end_box(...$args) {
	print '[/box]';
}

function cacti_log($message, $output = false, $environ = 'CMDPHP') {
	$GLOBALS['logs'][] = array($environ, $message);
}

function debounce_run_notification($id, $frequency = 7200) {
	$GLOBALS['debounce'] = array($id, $frequency);

	return $GLOBALS['scenario']['debounce'];
}

PHP;

	$source .= cacti_test_function_source($auth, 'auth_legacy_md5_users') . "\n\n";
	$source .= cacti_test_function_source($admin, 'user_legacy_hash_notice') . "\n\n";
	$source .= cacti_test_function_source($maint, 'secpass_report_legacy_hashes') . "\n\n";
	$source .= <<<'PHP'
ob_start();
$value = $scenario['call']();
$output = ob_get_clean();

print json_encode(array('value' => $value, 'output' => $output, 'logs' => $GLOBALS['logs'], 'debounce' => $GLOBALS['debounce'] ?? null));
PHP;

	return cacti_test_run_php_source($source, array('rows' => $rows, 'call' => $call, 'debounce' => $debounce));
}

function legacy_md5_rows() : array {
	return array(
		array(1, 'admin', 0, md5('admin')),
		array(2, 'bob', 0, password_hash('secret', PASSWORD_DEFAULT)),
		array(3, 'carol', 3, md5('carol')),
		array(4, 'guest', 0, ''),
		array(5, 'dave', 0, md5('dave')),
		array(6, 'erin', 1000, 'invalid'),
	);
}

test('only local accounts with an MD5 hash are listed, by username', function () {
	$result = legacy_md5_report_run(legacy_md5_rows(), 'auth_legacy_md5_users');

	expect($result['value'])->toBe(array(
		array('id' => 1, 'username' => 'admin'),
		array('id' => 5, 'username' => 'dave'),
	));
});

test('the user management notice names each account and escapes the names', function () {
	$rows   = legacy_md5_rows();
	$rows[] = array(7, '<b>eve</b>', 0, md5('eve'));

	$result = legacy_md5_report_run($rows, 'user_legacy_hash_notice');

	expect($result['output'])->toContain('[box:Legacy Password Hashes]')
		->and($result['output'])->toContain('3 local account(s) still store an unsalted MD5 password hash: &lt;b&gt;eve&lt;/b&gt;, admin, dave.')
		->and($result['output'])->not->toContain('<b>eve</b>')
		->and($result['output'])->not->toContain('bob');
});

test('the notice lists 25 names and counts the rest', function () {
	$rows = array();

	for ($i = 1; $i <= 30; $i++) {
		$rows[] = array($i, sprintf('user%02d', $i), 0, md5("p$i"));
	}

	$result = legacy_md5_report_run($rows, 'user_legacy_hash_notice');

	expect($result['output'])->toContain('30 local account(s)')
		->and($result['output'])->toContain('user25, and 5 more.')
		->and($result['output'])->not->toContain('user26');
});

test('the notice is absent when no account has an MD5 hash', function () {
	$rows = array(array(2, 'bob', 0, password_hash('secret', PASSWORD_DEFAULT)));

	$result = legacy_md5_report_run($rows, 'user_legacy_hash_notice');

	expect($result['output'])->toBe('');
});

test('maintenance logs the count once a day and changes nothing', function () {
	$result = legacy_md5_report_run(legacy_md5_rows(), 'secpass_report_legacy_hashes');

	expect($result['debounce'])->toBe(array('legacy_md5_hashes', 86400))
		->and($result['logs'])->toHaveCount(1)
		->and($result['logs'][0][0])->toBe('AUTH')
		->and($result['logs'][0][1])->toStartWith('WARNING: 2 local account(s) still use a legacy MD5 password hash.');
});

test('maintenance stays quiet inside the daily window or when no MD5 hash remains', function () {
	$debounced = legacy_md5_report_run(legacy_md5_rows(), 'secpass_report_legacy_hashes', false);
	$none      = legacy_md5_report_run(array(array(2, 'bob', 0, password_hash('secret', PASSWORD_DEFAULT))), 'secpass_report_legacy_hashes');

	expect($debounced['logs'])->toBe(array())
		->and($none['logs'])->toBe(array());
});
