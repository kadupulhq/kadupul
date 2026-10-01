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
 * With "Force Password Change on Weak Password" on (secpass_forceold), a
 * local login whose password fails the complexity policy must be completed
 * and sent to the forced password change. The shipped code redirected to the
 * change page before a session existed, so the change page sent the user back
 * to the login page on every attempt. It also ran for unknown usernames, so
 * the response revealed whether a username existed. An account that may not
 * change its password cannot finish the change, so its login is refused as
 * 1.2.31 refused it, with the same error as a wrong password.
 *
 * The shipped login functions run in a child process against an in-memory
 * user_auth table. A run that ends in exit reports finished=false.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function force_old_login_run(string $username, string $password, string $forceold = 'on', string $password_change = 'on') : array {
	$auth = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

define('POLLER_VERBOSITY_DEBUG', 5);
define('MESSAGE_LEVEL_INFO', 1);

$GLOBALS['req']      = array('login_password' => $scenario['password']);
$GLOBALS['config']   = array('secpass_forceold' => $scenario['forceold'], 'secpass_minlen' => 8, 'secpass_reqnum' => 'on');
$GLOBALS['users']    = array(
	array('id' => 42, 'username' => 'alice', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'hash:weak', 'must_change_password' => '', 'password_change' => $scenario['password_change']),
	array('id' => 43, 'username' => 'carol', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'hash:Strong123', 'must_change_password' => '', 'password_change' => 'on'),
);
$GLOBALS['messages'] = array();
$GLOBALS['user']     = null;
$GLOBALS['finished'] = false;

$error     = false;
$error_msg = '';

register_shutdown_function(function () {
	print json_encode(array(
		'user'      => $GLOBALS['user'],
		'error'     => $GLOBALS['error'],
		'error_msg' => $GLOBALS['error_msg'],
		'messages'  => $GLOBALS['messages'],
		'flags'     => array_column($GLOBALS['users'], 'must_change_password', 'id'),
		'finished'  => $GLOBALS['finished'],
	));
});

function user_rows(string $sql, array $params) : array {
	$where = (string) stristr($sql, 'WHERE');

	preg_match_all("/`?([a-z_]+)`?\s*=\s*(\?|'[^']*'|\d+)/i", $where, $matches, PREG_SET_ORDER);

	$bind = substr_count(substr($sql, 0, strlen($sql) - strlen($where)), '?');
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
	return $GLOBALS['config'][$name] ?? '';
}

function raise_message($id, $message = '', $level = 0) {
	$GLOBALS['messages'][] = $id;
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
	$rows = user_rows($sql, $params);

	return $rows ? reset($rows) : array();
}

function db_fetch_cell_prepared($sql, $params = array()) {
	$rows = user_rows($sql, $params);

	return $rows ? reset($rows)['password'] : '';
}

function db_execute_prepared($sql, $params = array()) {
	if (preg_match("/^UPDATE user_auth\s+SET must_change_password = 'on'/", trim($sql))) {
		foreach (array_keys(user_rows($sql, $params)) as $index) {
			$GLOBALS['users'][$index]['must_change_password'] = 'on';
		}
	}

	return true;
}

function compat_password_verify($password, $hash) {
	return $hash === 'hash:' . $password;
}

function compat_password_needs_rehash($password, $algo, $options = array()) {
	return false;
}

PHP;

	foreach (array('auth_dummy_password_hash', 'secpass_login_process', 'local_auth_login_process', 'secpass_check_pass') as $name) {
		$source .= cacti_test_function_source($auth, $name) . "\n\n";
	}

	$source .= "\$GLOBALS['user'] = local_auth_login_process(\$scenario['username']);\n";
	$source .= "\$GLOBALS['finished'] = true;\n";

	return cacti_test_run_php_source($source, array('username' => $username, 'password' => $password, 'forceold' => $forceold, 'password_change' => $password_change));
}

test('a correct password that fails the policy completes the login and flags the forced change', function () {
	$result = force_old_login_run('alice', 'weak');

	expect($result['finished'])->toBeTrue()
		->and($result['error'])->toBeFalse()
		->and($result['user']['id'] ?? null)->toBe(42)
		->and($result['user']['must_change_password'] ?? null)->toBe('on')
		->and($result['user']['password_change'] ?? null)->toBe('on')
		->and($result['flags'])->toBe(array(42 => 'on', 43 => ''))
		->and($result['messages'])->toBe(array('forced_password'));
});

test('a correct password that fails the policy is refused when the account may not change it', function () {
	$refused = force_old_login_run('alice', 'weak', 'on', '');
	$wrong   = force_old_login_run('alice', 'short', 'on', '');

	expect($refused['finished'])->toBeTrue()
		->and($refused['error'])->toBeTrue()
		->and($refused['error_msg'])->toBe($wrong['error_msg'])
		->and($refused['messages'])->toBe(array())
		->and($refused['flags'])->toBe(array(42 => '', 43 => ''));
});

test('a weak password is accepted as before when the option is off and the account may not change it', function () {
	$result = force_old_login_run('alice', 'weak', '', '');

	expect($result['error'])->toBeFalse()
		->and($result['user']['id'] ?? null)->toBe(42)
		->and($result['user']['must_change_password'] ?? null)->toBe('');
});

test('an unknown username fails exactly as a wrong password for a known one does', function () {
	$unknown = force_old_login_run('nobody', 'short');
	$known   = force_old_login_run('alice', 'short');

	foreach (array($unknown, $known) as $result) {
		expect($result['finished'])->toBeTrue()
			->and($result['error'])->toBeTrue()
			->and($result['user'])->toBe(array())
			->and($result['messages'])->toBe(array())
			->and($result['flags'])->toBe(array(42 => '', 43 => ''));
	}

	expect($unknown['error_msg'])->toBe($known['error_msg']);
});

test('a wrong password that fails the policy flags nothing', function () {
	$result = force_old_login_run('carol', 'weak');

	expect($result['finished'])->toBeTrue()
		->and($result['error'])->toBeTrue()
		->and($result['flags'])->toBe(array(42 => '', 43 => ''));
});

test('a correct password that meets the policy logs in without a forced change', function () {
	$result = force_old_login_run('carol', 'Strong123');

	expect($result['error'])->toBeFalse()
		->and($result['user']['id'] ?? null)->toBe(43)
		->and($result['user']['must_change_password'] ?? null)->toBe('')
		->and($result['messages'])->toBe(array());
});

test('a weak password is accepted as before when the option is off', function () {
	$result = force_old_login_run('alice', 'weak', '');

	expect($result['error'])->toBeFalse()
		->and($result['user']['must_change_password'] ?? null)->toBe('')
		->and($result['flags'])->toBe(array(42 => '', 43 => ''));
});
