<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Login log lines carry the login name from the request. It is logged with
 * control characters removed and cut to 64 characters, so one request
 * cannot write an unbounded or misleading line to the Cacti log. The shipped
 * local login runs in a child process and its log lines are captured.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function login_log_clamp_run(string $username) : array {
	$auth = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

define('POLLER_VERBOSITY_DEBUG', 5);

$GLOBALS['logged'] = array();

$error     = false;
$error_msg = '';

function get_nfilter_request_var($name, $default = '') {
	return $name == 'login_password' ? 'guess' : $default;
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function cacti_log($message, ...$args) {
	$GLOBALS['logged'][] = $message;
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function read_config_option($name, $force = false) {
	return '';
}

function api_plugin_hook_function($name, $parm = null) {
	return $parm;
}

function auth_checkclear_lockout($username, $realm) {
}

function auth_process_lockout_check($username, $realm) {
	return false;
}

function db_column_exists($table, $column) {
	return true;
}

function db_fetch_row_prepared($sql, $params = array()) {
	return array();
}

function db_fetch_cell_prepared($sql, $params = array()) {
	return '';
}

function compat_password_verify($password, $hash) {
	return false;
}

PHP;

	foreach (array('auth_log_username', 'auth_password_too_long', 'auth_dummy_password_hash', 'auth_login_throttle_check', 'secpass_login_process', 'local_auth_login_process') as $name) {
		$source .= cacti_test_function_source($auth, $name) . "\n\n";
	}

	$source .= "local_auth_login_process(\$scenario['username']);\n";
	$source .= "print json_encode(array('logged' => \$GLOBALS['logged']));\n";

	return cacti_test_run_php_source($source, array('username' => $username));
}

test('an unknown login name is logged without control characters', function () {
	$result = login_log_clamp_run("mallory\x1b[2J\x00\tadmin");

	expect($result['logged'])->toBe(array('LOGIN FAILED: Invalid user mallory[2Jadmin specified.'));
});

test('an unknown login name is logged without Unicode controls', function () {
	$result = login_log_clamp_run("mallory\u{0085}\u{2028}\u{202E}nimda");

	expect($result['logged'])->toBe(array('LOGIN FAILED: Invalid user mallorynimda specified.'));
});

test('invalid UTF-8 in a login name is replaced before it is logged', function () {
	$source  = "<?php\n" . cacti_test_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php'), 'auth_log_username') . "\n\n";
	$source .= "\$name = auth_log_username(base64_decode(json_decode(stream_get_contents(STDIN), true)['name']));\n";
	$source .= "print json_encode(array('name' => \$name, 'valid' => mb_check_encoding(\$name, 'UTF-8')));\n";

	$result = cacti_test_run_php_source($source, array('name' => base64_encode("alice\xC3\x28\x1b")));

	expect($result)->toBe(array('name' => 'alice?(', 'valid' => true));
});

test('a long login name is logged cut to 64 characters', function () {
	$result = login_log_clamp_run(str_repeat('é', 70) . 'tail');

	expect($result['logged'])->toBe(array('LOGIN FAILED: Invalid user ' . str_repeat('é', 64) . ' specified.'));
});

test('an ordinary login name is logged unchanged', function () {
	$result = login_log_clamp_run('alice@example.com');

	expect($result['logged'])->toBe(array('LOGIN FAILED: Invalid user alice@example.com specified.'));
});

/*
 * The LDAP and Domains realms log the same submitted name before any
 * directory answer, so they get the same clamp.
 */
function directory_log_clamp_run(string $function, array $scenario) : array {
	$auth = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

$GLOBALS['logged'] = array();
$GLOBALS['req']    = $scenario['request'];

$realm     = 0;
$error     = false;
$error_msg = '';

function get_nfilter_request_var($name, $default = '') {
	return $GLOBALS['req'][$name] ?? $default;
}

function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array()) {
	return $GLOBALS['req'][$name] ?? '';
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function cacti_log($message, ...$args) {
	$GLOBALS['logged'][] = $message;
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function read_config_option($name, $force = false) {
	return $name == 'ldap_server' ? 'ldap.example.com' : '';
}

function get_client_addr() {
	return '203.0.113.9';
}

function get_auth_realms($login = false) {
	return array(0 => 'Local', 2 => 'Web Basic', 3 => 'LDAP', 1001 => 'Domain 1');
}

/* login throttling is off by default and has its own test */
function auth_login_throttle_check($username, $realm) {
	return false;
}

function auth_checkclear_lockout($username, $realm) {
}

function auth_process_lockout_check($username, $realm) {
	return false;
}

function auth_process_lockout($username, $realm) {
}

function auth_ldap_equalize_failure($started) {
}

function cacti_ldap_search_dn($username, $dn = '', $host = '') {
	return array('error_num' => '0', 'error_text' => '', 'dn' => 'uid=x,dc=example,dc=com');
}

function cacti_ldap_auth($username, $password = '', $dn = '', $host = '') {
	return array('error_num' => '0', 'error_text' => '');
}

function domains_ldap_search_dn($username, $realm, $host = '') {
	return array('error_num' => '0', 'error_text' => '', 'dn' => 'uid=x,dc=example,dc=com');
}

function domains_ldap_auth($username, $password = '', $dn = '', $realm = 0, $host = '') {
	return array('error_num' => '0', 'error_text' => '');
}

/* one directory server, which always answers */
function domains_ldap_servers($realm) {
	return array('ldap.example.com');
}

function cacti_ldap_search_next_server($response) {
	return false;
}

function cacti_ldap_bind_next_server($response, $search_skipped) {
	return false;
}

function user_copy(...$args) {
	return true;
}

function db_fetch_row_prepared($sql, $params = array()) {
	return strpos($sql, 'WHERE id = ?') !== false ? array('username' => 'template') : array();
}

function db_fetch_cell_prepared($sql, $params = array()) {
	if (strpos($sql, 'domain_name') !== false) {
		return 'ExampleDomain';
	}

	return strpos($sql, 'SELECT user_id') !== false ? 5 : '';
}

PHP;

	$source .= cacti_test_function_source($auth, 'auth_log_username') . "\n\n";
	$source .= cacti_test_function_source($auth, $function) . "\n\n";
	$source .= $function . "(\$scenario['username']);\n";
	$source .= "print json_encode(array('logged' => \$GLOBALS['logged']));\n";

	return cacti_test_run_php_source($source, $scenario);
}

function directory_log_clamp_name() : string {
	return "eve\x1b[2J\tadmin" . str_repeat('A', 5000);
}

function directory_log_clamp_logged() : string {
	return 'eve[2Jadmin' . str_repeat('A', 53);
}

test('LDAP login log lines carry the clamped name', function () {
	$clamped = directory_log_clamp_logged();

	$result = directory_log_clamp_run('ldap_login_process', array(
		'username' => directory_log_clamp_name(),
		'request'  => array('login_password' => ''),
	));

	expect($result['logged'])->toBe(array('LOGIN FAILED: LDAP No password provided for user ' . $clamped));

	$result = directory_log_clamp_run('ldap_login_process', array(
		'username' => directory_log_clamp_name(),
		'request'  => array('login_password' => 'secret'),
	));

	expect($result['logged'])->toBe(array("LOGIN: LDAP User '" . $clamped . "' Authenticated"));
});

test('Domains login log lines carry the clamped name', function () {
	$clamped = directory_log_clamp_logged();
	$cases   = array(
		array(
			array('realm' => '7', 'login_password' => 'secret'),
			array("LOGIN FAILED: Unknown Login Realm '7' provided for user '" . $clamped . "' from IP address 203.0.113.9"),
		),
		array(
			array('realm' => '1001', 'login_password' => ''),
			array('LOGIN FAILED: LDAP No password provided for user ' . $clamped),
		),
		array(
			array('realm' => '3', 'login_password' => 'secret'),
			array("LOGIN FAILED: Login Realm '3' is not an LDAP domain for user '" . $clamped . "' from IP address 203.0.113.9"),
		),
		array(
			array('realm' => '1001', 'login_password' => 'secret'),
			array(
				"LOGIN: LDAP User '" . $clamped . "' Authenticated from Domain 'ExampleDomain'",
				"NOTE: User '" . $clamped . "' does not exist, copying template user",
			),
		),
	);

	foreach ($cases as [$request, $expected]) {
		$result = directory_log_clamp_run('domains_login_process', array(
			'username' => directory_log_clamp_name(),
			'request'  => $request,
		));

		expect($result['logged'])->toBe($expected);
	}
});
