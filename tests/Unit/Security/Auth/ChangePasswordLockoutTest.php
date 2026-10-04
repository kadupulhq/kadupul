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
*/

/*
 * The change password page asks for the current password before it accepts a
 * new one. A wrong guess only printed an error, so a borrowed or stolen
 * session could guess the account password without limit, and the history
 * check ran first and answered a second kind of guess. Failed guesses now
 * count toward the login lockout (secpass_lockfailed) and the current
 * password is checked before anything else compares against the stored hash.
 *
 * The shipped page runs in a child process from a directory whose
 * include/global.php is a stub over an in-memory user_auth table; the
 * lockout, policy, session binding and per-request session check functions
 * are the shipped ones.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function change_password_run(array $request, array $user = array(), array $config = array()) : array {
	$root = dirname(__DIR__, 4);
	$work = sys_get_temp_dir() . '/kadupul-acp-' . bin2hex(random_bytes(6));
	$auth = file_get_contents($root . '/lib/auth.php');

	mkdir($work . '/include', 0700, true);

	$global = <<<'PHP'
<?php
$GLOBALS['scenario'] = json_decode(stream_get_contents(STDIN), true);

define('OPER_MODE_NATIVE', 0);
define('OPER_MODE_RESKIN', 2);
define('POLLER_VERBOSITY_DEBUG', 5);
define('POLLER_VERBOSITY_LOW', 2);
define('PASSWORD_DEFAULT_STUB', 1);

$config   = array('url_path' => '/cacti/');
$_SESSION = array('sess_user_id' => '42');
$GLOBALS['users']   = array($GLOBALS['scenario']['user']);
$GLOBALS['history_calls'] = 0;
$GLOBALS['changed'] = false;

register_shutdown_function(function () {
	print json_encode(array(
		'message'  => strip_tags((string) ($GLOBALS['errorMessage'] ?? '')),
		'user'     => $GLOBALS['users'][0],
		'history'  => $GLOBALS['history_calls'],
		'changed'  => $GLOBALS['changed'],
	));
});

function user_rows(string $sql, array $params, int $bind) : array {
	$where = (string) stristr($sql, 'WHERE');

	preg_match_all("/`?([a-z_]+)`?\s*=\s*(\?|'[^']*'|\d+)/i", $where, $matches, PREG_SET_ORDER);

	$rows = array();

	foreach ($GLOBALS['users'] as $index => $row) {
		$b = $bind;

		foreach ($matches as $match) {
			$value = $match[2] === '?' ? $params[$b++] : trim($match[2], "'");

			if ((string) $row[$match[1]] !== (string) $value) {
				continue 2;
			}
		}

		$rows[$index] = $row;
	}

	return $rows;
}

function read_config_option($name, $force = false) {
	return $GLOBALS['scenario']['config'][$name] ?? '';
}

function set_default_action($default = '') {
}

function get_request_var($name, $default = '') {
	return $GLOBALS['scenario']['request'][$name] ?? $default;
}

function get_nfilter_request_var($name, $default = '') {
	return get_request_var($name, $default);
}

function validate_redirect_url($url = '', $default = 'index.php') {
	return $default;
}

function get_cacti_version() {
	return '1.2.32';
}

function get_guest_account() {
	return 0;
}

function get_client_addr() {
	return '192.0.2.10';
}

function kill_session_var($name) {
	unset($_SESSION[$name]);
}

function cacti_header($location) {
}

function cacti_session_destroy() {
}

function cacti_session_start($regenerate = false) {
}

function cacti_log(...$args) {
}

function raise_message($id, $message = '', $level = 0) {
}

function api_plugin_hook_function($name, $parm = null) {
	/* stop after the form handler; the page body is not under test */
	return $name == 'custom_password' ? OPER_MODE_RESKIN : $parm;
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function secpass_check_history($id, $password) {
	$GLOBALS['history_calls']++;

	return true;
}

function compat_password_verify($password, $hash) {
	return $hash === 'hash:' . $password;
}

function compat_password_hash($password, $algo) {
	return 'hash:' . $password;
}

function db_check_password_length() {
}

function db_fetch_row_prepared($sql, $params = array()) {
	$rows = user_rows($sql, $params, 0);

	return $rows ? reset($rows) : array();
}

function db_fetch_cell_prepared($sql, $params = array()) {
	if (!preg_match('/SELECT\s+`?([a-z_]+)`?\s+FROM user_auth\b/i', $sql, $match)) {
		return false;
	}

	$rows = user_rows($sql, $params, 0);

	return $rows ? reset($rows)[$match[1]] : false;
}

function db_execute_prepared($sql, $params = array()) {
	$sql = trim(preg_replace('/\s+/', ' ', $sql));

	if (!preg_match('/^UPDATE user_auth SET (.*?) WHERE/', $sql, $set)) {
		return true;
	}

	$bind    = 0;
	$changes = array();

	foreach (explode(', ', $set[1]) as $assignment) {
		list($column, $value) = array_map('trim', explode('=', $assignment, 2));

		$column = trim($column, '`');

		if ($value === '?') {
			$changes[$column] = $params[$bind++];
		} elseif ($value === $column . ' + 1') {
			$changes[$column] = '+1';
		} else {
			$changes[$column] = trim($value, "'");
		}
	}

	foreach (array_keys(user_rows($sql, $params, $bind)) as $index) {
		foreach ($changes as $column => $value) {
			$GLOBALS['users'][$index][$column] = $value === '+1' ? $GLOBALS['users'][$index][$column] + 1 : $value;
		}

		if (isset($changes['password'])) {
			$GLOBALS['changed'] = true;
		}
	}

	return true;
}

PHP;

	foreach (array('auth_log_username', 'auth_session_credential_key', 'auth_session_credentials_valid', 'auth_session_epoch', 'auth_session_end_reason', 'auth_session_enforce', 'auth_checkclear_lockout', 'auth_process_lockout_check', 'auth_process_lockout', 'auth_password_too_long', 'secpass_check_pass') as $name) {
		$global .= cacti_test_function_source($auth, $name) . "\n\n";
	}

	file_put_contents($work . '/include/global.php', $global);
	copy($root . '/auth_changepassword.php', $work . '/auth_changepassword.php');

	$cwd = getcwd();
	chdir($work);

	try {
		return cacti_test_run_php_file($work . '/auth_changepassword.php', array(
			'request' => $request + array('action' => 'changepassword'),
			'user'    => $user + array('id' => 42, 'username' => 'alice', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'lastfail' => 0, 'failed_attempts' => 0, 'password' => 'hash:Old-pass1', 'password_change' => 'on', 'must_change_password' => '', 'password_history' => ''),
			'config'  => $config + array('secpass_lockfailed' => 3, 'secpass_unlocktime' => 0, 'secpass_minlen' => 8),
		));
	} finally {
		chdir($cwd);
		unlink($work . '/include/global.php');
		unlink($work . '/auth_changepassword.php');
		rmdir($work . '/include');
		rmdir($work);
	}
}

function change_password_request(string $current) : array {
	return array('current_password' => $current, 'password' => 'New-pass1', 'password_confirm' => 'New-pass1');
}

test('a wrong current password counts toward the lockout', function () {
	$result = change_password_run(change_password_request('guess'));

	expect($result['message'])->toBe('Your current password is not correct. Please try again.')
		->and($result['user']['failed_attempts'])->toBe(1)
		->and($result['user']['locked'])->toBe('')
		->and($result['changed'])->toBeFalse();
});

test('the last allowed wrong guess locks the account', function () {
	$result = change_password_run(change_password_request('guess'), array('failed_attempts' => 2));

	expect($result['user']['failed_attempts'])->toBe(3)
		->and($result['user']['locked'])->toBe('on')
		->and($result['message'])->toBe('Your account has been locked.  Please contact your Administrator.')
		->and($result['changed'])->toBeFalse();
});

test('a locked account can not change its password even with the right current password', function () {
	$result = change_password_run(change_password_request('Old-pass1'), array('failed_attempts' => 3, 'locked' => 'on'));

	expect($result['message'])->toBe('Your account has been locked.  Please contact your Administrator.')
		->and($result['user']['password'])->toBe('hash:Old-pass1')
		->and($result['changed'])->toBeFalse();
});

test('a wrong current password is refused before the history check runs', function () {
	$result = change_password_run(change_password_request('guess'));

	expect($result['history'])->toBe(0);
});

test('the right current password still changes the password', function () {
	$result = change_password_run(change_password_request('Old-pass1'));

	expect($result['changed'])->toBeTrue()
		->and($result['user']['password'])->toBe('hash:New-pass1')
		->and($result['user']['failed_attempts'])->toBe(0)
		->and($result['history'])->toBe(1);
});

test('wrong guesses are not counted when the lockout is off, as in 1.2.31', function () {
	$result = change_password_run(change_password_request('guess'), array(), array('secpass_lockfailed' => 0));

	expect($result['message'])->toBe('Your current password is not correct. Please try again.')
		->and($result['user']['failed_attempts'])->toBe(0);
});
