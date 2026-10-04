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
 * link.php and auth_changepassword.php load include/global.php but not
 * include/auth.php, so the per-request session check there never ran. A
 * session that "logout everywhere" ended, or an account an administrator
 * disabled, still opened external link pages and the password form, and an
 * auto-refreshing external link page never recorded activity, so its viewer
 * was later logged out as idle.
 *
 * Each page runs from a copy whose include/global.php stub carries the real
 * session functions from lib/auth.php.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function session_page_run(string $page, array $scenario) : array {
	$root = dirname(__DIR__, 4);
	$work = sys_get_temp_dir() . '/kadupul-session-page-' . bin2hex(random_bytes(6));
	$auth = file_get_contents($root . '/lib/auth.php');

	mkdir($work . '/include', 0700, true);

	/* link.php sends headers, so the stubs and the page share a namespace that can define header() */
	$global = <<<'PHP'
<?php
namespace SessionPage;

$GLOBALS['scenario'] = json_decode(stream_get_contents(STDIN), true);
$GLOBALS['events']   = array();
$GLOBALS['lookups']  = 0;

$config   = array('url_path' => '/cacti/', 'base_path' => getcwd());
$_SESSION = $GLOBALS['scenario']['session'];

ini_set('session.gc_maxlifetime', (string) $GLOBALS['scenario']['gc_maxlifetime']);

register_shutdown_function(function () {
	while (ob_get_level() > 0) {
		ob_end_clean();
	}

	print json_encode(array('session' => $_SESSION, 'events' => $GLOBALS['events'], 'lookups' => $GLOBALS['lookups']));
});

ob_start();

function get_guest_account() {
	return '3';
}

function cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function cacti_log($message, $output = false, $environ = '', $level = 0) {
}

function kill_session_var($name) {
	unset($_SESSION[$name]);
}

function cacti_session_destroy() {
	$GLOBALS['events'][] = 'session_destroy';
	$_SESSION = array();
}

function cacti_session_start($regenerate = false) {
	$GLOBALS['events'][] = 'session_start';
}

function db_fetch_row_prepared($sql, $params = array()) {
	if (strpos($sql, 'external_links') !== false) {
		return array('id' => 4, 'title' => 'Status', 'style' => 'CONSOLE', 'contentfile' => 'https://example.org/', 'enabled' => 'on', 'refresh' => 60);
	}

	if (strpos($sql, 'SELECT enabled, password') !== false) {
		return $GLOBALS['scenario']['account'];
	}

	$GLOBALS['lookups']++;

	return array();
}

function db_fetch_assoc_prepared($sql, $params = array()) {
	$epoch = $GLOBALS['scenario']['epoch'];

	return $epoch === false ? false : array(array('value' => $epoch));
}

function db_fetch_cell_prepared($sql, $params = array()) {
	if (strpos($sql, 'FROM settings_user') !== false) {
		return $GLOBALS['scenario']['epoch'];
	}

	return $GLOBALS['scenario']['account']['password'] ?? false;
}

function get_filter_request_var($name) {
	return '4';
}

function get_request_var($name, $default = '') {
	return $name == 'id' ? '4' : $default;
}

function set_default_action($default = '') {
}

function validate_redirect_url($url = '', $default = 'index.php') {
	return $default;
}

/* mirrors lib/auth.php: no session user, no realm */
function is_realm_allowed($realm) {
	return isset($_SESSION['sess_user_id']);
}

function raise_message($id, $message = '', $level = 0) {
	$GLOBALS['events'][] = 'message:' . $id;
}

function header($value) {
	$GLOBALS['events'][] = $value;
}

function cacti_header($location) {
	$GLOBALS['events'][] = 'Location: ' . $location;
}

function html_escape($text) {
	return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function general_header() {
	$GLOBALS['events'][] = 'rendered';
}

function top_header() {
	$GLOBALS['events'][] = 'rendered';
}

function bottom_footer() {
}

PHP;

	foreach (array('auth_session_credential_key', 'auth_session_credentials_valid', 'auth_session_epoch', 'auth_session_end_reason', 'auth_session_enforce') as $name) {
		$global .= cacti_test_function_source($auth, $name) . "\n\n";
	}

	file_put_contents($work . '/include/global.php', $global);
	file_put_contents($work . '/' . $page, preg_replace('/^<\?php/', '<?php namespace SessionPage;', file_get_contents($root . '/' . $page)));

	$cwd = getcwd();
	chdir($work);

	try {
		return cacti_test_run_php_file($work . '/' . $page, $scenario + array(
			'account'        => array('enabled' => 'on', 'password' => 'hash'),
			'epoch'          => false,
			'gc_maxlifetime' => 1440,
		));
	} finally {
		chdir($cwd);
		unlink($work . '/include/global.php');
		unlink($work . '/' . $page);
		rmdir($work . '/include');
		rmdir($work);
	}
}

function session_page_session(array $extra = array()) : array {
	return $extra + array(
		'sess_user_id'         => '42',
		'sess_user_credential' => hash('sha256', 'hash'),
		'sess_user_epoch'      => '0',
		'sess_last_activity'   => time() - 60,
	);
}

function session_page_ended(array $result) : void {
	expect($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['events'])->toContain('session_destroy');
}

test('an external link page ends a session that logout everywhere replaced', function () {
	$result = session_page_run('link.php', array('session' => session_page_session(), 'epoch' => '1'));

	session_page_ended($result);

	expect($result['events'])->toContain('message:permission_denied')
		->and($result['events'])->not->toContain('rendered');
});

test('an external link page ends a session for an account an administrator disabled', function () {
	$result = session_page_run('link.php', array('session' => session_page_session(), 'account' => array('enabled' => '', 'password' => 'hash')));

	session_page_ended($result);

	expect($result['events'])->not->toContain('rendered');
});

test('an external link page ends a session idle for longer than session.gc_maxlifetime', function () {
	$result = session_page_run('link.php', array('session' => session_page_session(array('sess_last_activity' => time() - 2000))));

	session_page_ended($result);

	expect($result['events'])->not->toContain('rendered');
});

test('an auto-refreshing external link page records activity, so its viewer is not logged out as idle', function () {
	$before = time() - 1000;
	$result = session_page_run('link.php', array('session' => session_page_session(array('sess_last_activity' => $before))));

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['session']['sess_last_activity'])->toBeGreaterThan($before)
		->and($result['events'])->toContain('rendered')
		->and($result['events'])->not->toContain('session_destroy');
});

test('the change password page ends a session that logout everywhere replaced', function () {
	$result = session_page_run('auth_changepassword.php', array('session' => session_page_session(array('sess_change_password' => true)), 'epoch' => '1'));

	session_page_ended($result);

	expect($result['events'])->toContain('Location: index.php')
		->and($result['lookups'])->toBe(0);
});

test('the change password page ends a session for an account an administrator disabled', function () {
	$result = session_page_run('auth_changepassword.php', array('session' => session_page_session(), 'account' => array('enabled' => '', 'password' => 'hash')));

	session_page_ended($result);

	expect($result['events'])->toContain('Location: index.php')
		->and($result['lookups'])->toBe(0);
});

test('the change password page keeps a current session', function () {
	$result = session_page_run('auth_changepassword.php', array('session' => session_page_session()));

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['events'])->not->toContain('session_destroy')
		->and($result['lookups'])->toBe(1);
});
