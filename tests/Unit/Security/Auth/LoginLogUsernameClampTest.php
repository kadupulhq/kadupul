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

	foreach (array('auth_log_username', 'auth_password_too_long', 'auth_dummy_password_hash', 'secpass_login_process', 'local_auth_login_process') as $name) {
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

test('a long login name is logged cut to 64 characters', function () {
	$result = login_log_clamp_run(str_repeat('é', 70) . 'tail');

	expect($result['logged'])->toBe(array('LOGIN FAILED: Invalid user ' . str_repeat('é', 64) . ' specified.'));
});

test('an ordinary login name is logged unchanged', function () {
	$result = login_log_clamp_run('alice@example.com');

	expect($result['logged'])->toBe(array('LOGIN FAILED: Invalid user alice@example.com specified.'));
});
