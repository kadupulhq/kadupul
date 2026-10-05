<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Local login and the password policy refuse a password over 4096 bytes.
 * A login with one fails before any password is hashed, and a new password
 * that long fails the policy check that the change password page, its live
 * check and cli/reset_password.php share. The shipped functions run in a
 * child process with a counting password verifier.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function password_length_cap_run(string $call, string $username, string $password) : array {
	$auth = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

define('POLLER_VERBOSITY_DEBUG', 5);

$GLOBALS['req']          = array('login_password' => $scenario['password']);
$GLOBALS['users']        = array('alice' => array('id' => 42, 'username' => 'alice', 'enabled' => 'on', 'locked' => '', 'password' => 'known-hash', 'password_change' => 'on'));
$GLOBALS['verify_calls'] = 0;
$GLOBALS['logged']       = array();

$error     = false;
$error_msg = '';

function get_nfilter_request_var($name, $default = '') {
	return $GLOBALS['req'][$name] ?? $default;
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

	return $hash === 'known-hash' && $password === str_repeat('a', 4096);
}

function compat_password_needs_rehash($password, $algo, $options = array()) {
	return false;
}

PHP;

	foreach (array('auth_log_username', 'auth_password_too_long', 'auth_dummy_password_hash', 'auth_login_throttle_check', 'secpass_login_process', 'local_auth_login_process', 'secpass_check_pass') as $name) {
		$source .= cacti_test_function_source($auth, $name) . "\n\n";
	}

	if ($call == 'login') {
		$source .= "\$out = local_auth_login_process(\$scenario['username']);\n";
	} else {
		$source .= "\$out = secpass_check_pass(\$scenario['password']);\n";
	}

	$source .= "print json_encode(array('out' => \$out, 'error' => \$error, 'verify_calls' => \$GLOBALS['verify_calls'], 'logged' => \$GLOBALS['logged']));\n";

	return cacti_test_run_php_source($source, array('username' => $username, 'password' => $password));
}

test('a login with a password over 4096 bytes fails before any hashing', function () {
	foreach (array('alice', 'nobody') as $username) {
		$result = password_length_cap_run('login', $username, str_repeat('a', 4097));

		expect($result['out'])->toBe(array())
			->and($result['error'])->toBeTrue()
			->and($result['verify_calls'])->toBe(0)
			->and($result['logged'])->toContain('LOGIN FAILED: Password longer than 4096 bytes for user ' . $username);
	}
});

test('a 4096 byte password still logs in', function () {
	$result = password_length_cap_run('login', 'alice', str_repeat('a', 4096));

	expect($result['out']['id'] ?? null)->toBe(42)
		->and($result['error'])->toBeFalse();
});

test('the password policy refuses a new password over 4096 bytes', function () {
	$result = password_length_cap_run('check', 'alice', str_repeat('a', 4097));

	expect($result['out'])->toBe('Password must be no longer than 4096 bytes!');
});

test('the password policy accepts a 4096 byte password', function () {
	$result = password_length_cap_run('check', 'alice', str_repeat('a', 4096));

	expect($result['out'])->toBe('ok');
});
