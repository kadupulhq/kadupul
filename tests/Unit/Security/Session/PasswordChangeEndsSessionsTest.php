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
 * Changing or resetting a password must end the account's other sessions.
 * The shipped code deleted rows from the sessions table, which only holds
 * sessions when $cacti_db_session is on; the default is PHP's file storage,
 * where every session opened before the change stayed valid.
 *
 * A session now keeps a digest of the password hash it logged in with, and
 * include/auth.php and auth_changepassword.php end it once the stored hash
 * differs. That does not depend on where the session is stored. The shipped
 * include/auth.php and lib/auth.php run through the auth entry probe.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function password_change_user(string $password) : array {
	return array('id' => '42', 'username' => 'alice', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => $password, 'must_change_password' => '', 'password_change' => 'on');
}

function password_change_request(string $stored, array $session, array $extra = array()) : array {
	return $extra + array(
		'config'  => array('auth_method' => 1, 'guest_user' => 'guest'),
		'users'   => array(password_change_user($stored), array('id' => '3', 'username' => 'guest', 'realm' => 0, 'enabled' => '', 'locked' => '', 'password' => '')),
		'session' => $session,
	);
}

test('a session opened before a password change is ended on its next request', function () {
	$result = cacti_test_run_auth_entry_probe(password_change_request('new-hash', array(
		'sess_user_id'         => '42',
		'sess_user_credential' => hash('sha256', 'old-hash'),
	)));

	expect($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['events'])->toContain('session_destroy')
		->and($result['events'])->toContain('login_page')
		->and($result['page_continued'])->toBeFalse();
});

test('a session opened after the password change continues', function () {
	$result = cacti_test_run_auth_entry_probe(password_change_request('new-hash', array(
		'sess_user_id'         => '42',
		'sess_user_credential' => hash('sha256', 'new-hash'),
	)));

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['events'])->not->toContain('session_destroy')
		->and($result['page_continued'])->toBeTrue();
});

test('a session from before the upgrade is bound to the current password and continues', function () {
	$result = cacti_test_run_auth_entry_probe(password_change_request('new-hash', array('sess_user_id' => '42')));

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['session']['sess_user_credential'] ?? null)->toBe(hash('sha256', 'new-hash'))
		->and($result['page_continued'])->toBeTrue();
});

test('a malformed binding ends the session rather than passing', function () {
	$result = cacti_test_run_auth_entry_probe(password_change_request('new-hash', array(
		'sess_user_id'         => '42',
		'sess_user_credential' => '',
	)));

	expect($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['page_continued'])->toBeFalse();
});

test('a binding that is not a string ends the session rather than being replaced', function () {
	$result = cacti_test_run_auth_entry_probe(password_change_request('new-hash', array(
		'sess_user_id'         => '42',
		'sess_user_credential' => 42,
	)));

	expect($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['page_continued'])->toBeFalse();
});

test('a stale session on a guest page falls back to the guest account', function () {
	$result = cacti_test_run_auth_entry_probe(password_change_request('new-hash', array(
		'sess_user_id'         => '42',
		'sess_user_credential' => hash('sha256', 'old-hash'),
	), array('guest_account' => true)));

	expect($result['session']['sess_user_id'] ?? null)->toBe('3')
		->and($result['events'])->toContain('session_destroy')
		->and($result['page_continued'])->toBeTrue();
});

test('a remember-me login binds the new session to the current password', function () {
	$result = cacti_test_run_auth_entry_probe(array(
		'config' => array('auth_method' => 1, 'auth_cache_enabled' => 'on'),
		'users'  => array(password_change_user('new-hash')),
		'cache'  => array(array('user_id' => 42, 'token' => hash('sha512', 'valid-token'), 'hostname' => '192.0.2.10')),
		'cookie' => '42,0,valid-token',
	));

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['session']['sess_user_credential'] ?? null)->toBe(hash('sha256', 'new-hash'))
		->and($result['page_continued'])->toBeTrue();
});

test('a login transition binds the session to the current password', function () {
	$result = cacti_test_run_auth_entry_probe(array(
		'users' => array(password_change_user('new-hash')),
		'call'  => array('type' => 'cacti_auth_transition', 'args' => array(42, 'login')),
	));

	expect($result['return'])->toBeTrue()
		->and($result['session']['sess_user_credential'] ?? null)->toBe(hash('sha256', 'new-hash'));
});

test('a refused transition does not bind the session', function () {
	$user   = array('enabled' => '') + password_change_user('new-hash');
	$result = cacti_test_run_auth_entry_probe(array(
		'users' => array($user),
		'call'  => array('type' => 'cacti_auth_transition', 'args' => array(42, 'login')),
	));

	expect($result['return'])->toBeFalse()
		->and($result['session'])->not->toHaveKey('sess_user_credential');
});

/* auth_changepassword.php includes global.php rather than auth.php, so it checks the binding itself */
function password_change_page_run(array $session) : array {
	$root = dirname(__DIR__, 4);
	$work = sys_get_temp_dir() . '/kadupul-acp-' . bin2hex(random_bytes(6));
	$auth = file_get_contents($root . '/lib/auth.php');

	mkdir($work . '/include', 0700, true);

	$global = <<<'PHP'
<?php
$GLOBALS['scenario'] = json_decode(stream_get_contents(STDIN), true);

$config   = array('url_path' => '/cacti/');
$_SESSION = $GLOBALS['scenario']['session'];
$GLOBALS['calls'] = array('redirect' => null, 'lookups' => 0);

register_shutdown_function(function () {
	print json_encode(array('session' => $_SESSION, 'calls' => $GLOBALS['calls']));
});

function set_default_action($default = '') {
}

function get_request_var($name, $default = '') {
	return $default;
}

function validate_redirect_url($url = '', $default = 'index.php') {
	return $default;
}

function kill_session_var($name) {
	unset($_SESSION[$name]);
}

function cacti_header($location) {
	$GLOBALS['calls']['redirect'] = $location;
}

function raise_message($id, $message = '', $level = 0) {
}

function db_fetch_cell_prepared($sql, $params = array()) {
	return 'new-hash';
}

function db_fetch_row_prepared($sql, $params = array()) {
	if (strpos($sql, 'SELECT enabled, password') !== false) {
		return array('enabled' => 'on', 'password' => 'new-hash');
	}

	$GLOBALS['calls']['lookups']++;

	return array();
}

function get_guest_account() {
	return '3';
}

function cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function cacti_log($message, $output = false, $environ = '', $level = 0) {
}

function cacti_session_destroy() {
	$_SESSION = array();
}

function cacti_session_start($regenerate = false) {
}

PHP;

	foreach (array('auth_session_credential_key', 'auth_session_credentials_valid', 'auth_session_epoch', 'auth_session_end_reason', 'auth_session_enforce') as $name) {
		$global .= cacti_test_function_source($auth, $name) . "\n\n";
	}

	file_put_contents($work . '/include/global.php', $global);
	copy($root . '/auth_changepassword.php', $work . '/auth_changepassword.php');

	$cwd = getcwd();
	chdir($work);

	try {
		return cacti_test_run_php_file($work . '/auth_changepassword.php', array('session' => $session));
	} finally {
		chdir($cwd);
		unlink($work . '/include/global.php');
		unlink($work . '/auth_changepassword.php');
		rmdir($work . '/include');
		rmdir($work);
	}
}

test('the change password page treats a session from before the change as logged out', function () {
	$result = password_change_page_run(array(
		'sess_user_id'         => '42',
		'sess_change_password' => true,
		'sess_user_credential' => hash('sha256', 'old-hash'),
	));

	expect($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['session'])->not->toHaveKey('sess_change_password')
		->and($result['calls']['redirect'])->toBe('index.php')
		->and($result['calls']['lookups'])->toBe(0);
});

test('the change password page keeps a current session', function () {
	$result = password_change_page_run(array(
		'sess_user_id'         => '42',
		'sess_user_credential' => hash('sha256', 'new-hash'),
	));

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['calls']['lookups'])->toBe(1);
});

/* user_admin.php form_save runs with the request, database and session helpers stubbed */
function password_change_admin_save(string $session_user, string $target, string $password = 'N3w-password', string $stored = 'old-hash') : array {
	$root = dirname(__DIR__, 4);
	$auth = file_get_contents($root . '/lib/auth.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

$_SESSION = array('sess_user_id' => $scenario['session_user'], 'sess_user_credential' => hash('sha256', 'old-hash'));
$_POST    = array();
$GLOBALS['request']  = array('save_component_user' => 1, 'id' => $scenario['target'], 'username' => 'alice', 'realm' => 0, 'password' => $scenario['password'], 'password_confirm' => $scenario['password'], 'enabled' => 'on');
$GLOBALS['password'] = array('42' => $scenario['stored'], '43' => 'other-hash');

function isset_request_var($name) {
	return isset($GLOBALS['request'][$name]);
}

function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array()) {
	return $GLOBALS['request'][$name] ?? '';
}

function get_nfilter_request_var($name, $default = '') {
	return $GLOBALS['request'][$name] ?? $default;
}

function get_request_var($name, $default = '') {
	return $GLOBALS['request'][$name] ?? $default;
}

function form_input_validate($value, $name, $regex, $allow_nulls, $error) {
	return $value;
}

function compat_password_hash($password, $algo, $options = array()) {
	return 'new-hash';
}

function db_fetch_cell_prepared($sql, $params = array()) {
	if (strpos($sql, 'SELECT password') !== false) {
		return $GLOBALS['password'][(string) $params[0]] ?? false;
	}

	return '';
}

function db_fetch_row_prepared($sql, $params = array()) {
	return array();
}

function db_execute_prepared($sql, $params = array()) {
	return true;
}

function sql_save($save, $table) {
	$GLOBALS['password'][(string) $save['id']] = $save['password'];

	return $save['id'];
}

function read_config_option($name, $force = false) {
	return '';
}

function is_template_account($user_id) {
	return false;
}

function is_error_message() {
	return false;
}

function api_plugin_hook_function($name, $parm = null) {
	return $parm;
}

function raise_message($id, $message = '', $level = 0) {
}

function user_disable($user_id) {
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

PHP;

	foreach (array('auth_session_credential_key', 'auth_session_bind_credentials', 'auth_session_credentials_valid') as $name) {
		$source .= cacti_test_function_source($auth, $name) . "\n\n";
	}

	$source .= cacti_test_function_source(file_get_contents($root . '/user_admin.php'), 'form_save') . "\n\n";
	$source .= "form_save();\n";
	$source .= "print json_encode(array('valid' => auth_session_credentials_valid(\$_SESSION['sess_user_id'])));\n";

	return cacti_test_run_php_source($source, array('session_user' => $session_user, 'target' => $target, 'password' => $password, 'stored' => $stored));
}

test('an administrator who changes their own password keeps the session they used', function () {
	expect(password_change_admin_save('42', '42')['valid'])->toBeTrue();
});

test('an administrator who changes another password keeps their own session', function () {
	expect(password_change_admin_save('42', '43')['valid'])->toBeTrue();
});

test('a self-save that keeps the password does not rebind a session from before a change', function () {
	expect(password_change_admin_save('42', '42', '', 'new-hash')['valid'])->toBeFalse();
});
