<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * A local login for an unknown username must do the same password hashing
 * work as one for a known username, so response time does not reveal which
 * usernames exist. The shipped login functions run in a child process with a
 * counting password verifier.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function local_login_timing_run(string $username, string $password) : array {
	$auth = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

define('POLLER_VERBOSITY_DEBUG', 5);

$GLOBALS['req']          = array('login_password' => $scenario['password']);
$GLOBALS['users']        = array('alice' => array('id' => 42, 'username' => 'alice', 'enabled' => 'on', 'locked' => '', 'password' => 'known-hash'));
$GLOBALS['verify_calls'] = 0;

$error     = false;
$error_msg = '';

function get_nfilter_request_var($name, $default = '') {
	return $GLOBALS['req'][$name] ?? $default;
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function cacti_log(...$args) {
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

function auth_process_lockout($username, $realm) {
}

function db_column_exists($table, $column) {
	return true;
}

function db_fetch_row_prepared($sql, $params = array()) {
	return $GLOBALS['users'][$params[0]] ?? array();
}

function db_fetch_cell_prepared($sql, $params = array()) {
	return $GLOBALS['users'][$params[0]]['password'] ?? '';
}

function db_execute_prepared($sql, $params = array()) {
	return true;
}

function compat_password_verify($password, $hash) {
	$GLOBALS['verify_calls']++;

	return $hash === 'known-hash' && $password === 'right';
}

function compat_password_needs_rehash($password, $algo, $options = array()) {
	return false;
}

PHP;

	$source .= cacti_test_function_source($auth, 'secpass_login_process') . "\n\n";
	$source .= cacti_test_function_source($auth, 'local_auth_login_process') . "\n\n";
	$source .= "\$user = local_auth_login_process(\$scenario['username']);\n";
	$source .= "print json_encode(array('user' => \$user, 'error' => \$error, 'verify_calls' => \$GLOBALS['verify_calls']));\n";

	return cacti_test_run_php_source($source, array('username' => $username, 'password' => $password));
}

test('an unknown username runs as many password verifications as a known one', function () {
	$known   = local_login_timing_run('alice', 'guess');
	$unknown = local_login_timing_run('nobody', 'guess');

	expect($known['user'])->toBe(array())
		->and($unknown['user'])->toBe(array())
		->and($unknown['error'])->toBeTrue()
		->and($known['verify_calls'])->toBeGreaterThan(0)
		->and($unknown['verify_calls'])->toBe($known['verify_calls']);
});

test('a blank password runs as many password verifications for an unknown username as a known one', function () {
	$known   = local_login_timing_run('alice', '');
	$unknown = local_login_timing_run('nobody', '');

	expect($known['user'])->toBe(array())
		->and($unknown['user'])->toBe(array())
		->and($known['error'])->toBeTrue()
		->and($unknown['error'])->toBeTrue()
		->and($unknown['verify_calls'])->toBe($known['verify_calls']);
});

test('a correct password still returns the account', function () {
	$result = local_login_timing_run('alice', 'right');

	expect($result['user']['id'] ?? null)->toBe(42)
		->and($result['error'])->toBeFalse();
});
