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
 * A local login whose stored hash uses an outdated algorithm rehashes the
 * password. The UPDATE matched on username alone, so an LDAP or Domains row
 * with the same username received a hash of the local password. The shipped
 * login functions run in a child process against an in-memory user_auth
 * table that applies each UPDATE's WHERE clause.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function password_rehash_run(string $password, bool $needs_rehash = true, bool $locked = false) : array {
	$auth = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

define('POLLER_VERBOSITY_DEBUG', 5);

$GLOBALS['req']   = array('login_password' => $scenario['password']);
$GLOBALS['users'] = array(
	array('id' => 42, 'username' => 'alice', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'legacy-hash'),
	array('id' => 43, 'username' => 'alice', 'realm' => 3, 'enabled' => 'on', 'locked' => '', 'password' => ''),
	array('id' => 44, 'username' => 'bob', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'bob-hash'),
);

$error     = false;
$error_msg = '';

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
	return '';
}

function api_plugin_hook_function($name, $parm = null) {
	return $parm;
}

function auth_checkclear_lockout($username, $realm) {
}

function auth_process_lockout_check($username, $realm) {
	global $error, $error_msg;

	if ($GLOBALS['scenario']['locked']) {
		$error     = true;
		$error_msg = 'locked';

		return true;
	}

	return false;
}

function auth_process_lockout($username, $realm) {
}

function db_column_exists($table, $column) {
	return true;
}

function db_check_password_length() {
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
	if (preg_match('/^UPDATE user_auth\s+SET password = \?/', trim($sql))) {
		foreach (array_keys(user_rows($sql, $params)) as $index) {
			$GLOBALS['users'][$index]['password'] = $params[0];
		}
	}

	return true;
}

function compat_password_verify($password, $hash) {
	return $hash === 'legacy-hash' && $password === 'right';
}

function compat_password_needs_rehash($password, $algo, $options = array()) {
	return $GLOBALS['scenario']['needs_rehash'];
}

function compat_password_hash($password, $algo, $options = array()) {
	return 'new-hash';
}

PHP;

	$source .= cacti_test_function_source($auth, 'secpass_login_process') . "\n\n";
	$source .= cacti_test_function_source($auth, 'local_auth_login_process') . "\n\n";
	$source .= "\$user = local_auth_login_process('alice');\n";
	$source .= "print json_encode(array('user' => \$user, 'error' => \$error, 'passwords' => array_column(\$GLOBALS['users'], 'password', 'id')));\n";

	return cacti_test_run_php_source($source, array('password' => $password, 'needs_rehash' => $needs_rehash, 'locked' => $locked));
}

test('a rehash on local login rewrites only the local account', function () {
	$result = password_rehash_run('right');

	expect($result['user']['id'] ?? null)->toBe(42)
		->and($result['error'])->toBeFalse()
		->and($result['passwords'])->toBe(array(42 => 'new-hash', 43 => '', 44 => 'bob-hash'));
});

test('a wrong password rehashes nothing', function () {
	$result = password_rehash_run('wrong');

	expect($result['error'])->toBeTrue()
		->and($result['passwords'])->toBe(array(42 => 'legacy-hash', 43 => '', 44 => 'bob-hash'));
});

test('a current hash is left as it is', function () {
	$result = password_rehash_run('right', false);

	expect($result['user']['id'] ?? null)->toBe(42)
		->and($result['passwords'])->toBe(array(42 => 'legacy-hash', 43 => '', 44 => 'bob-hash'));
});

test('a locked account that knows its password is not rehashed', function () {
	$result = password_rehash_run('right', true, true);

	expect($result['error'])->toBeTrue()
		->and($result['passwords'])->toBe(array(42 => 'legacy-hash', 43 => '', 44 => 'bob-hash'));
});
