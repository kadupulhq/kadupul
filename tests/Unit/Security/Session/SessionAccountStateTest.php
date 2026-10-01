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
 * An open session outlived the account state it was opened under. Disabling
 * or deleting a user left their session working, and "logout everywhere"
 * deleted remember-me rows but no session. A remember-me cookie also restored
 * a session for a locked account whenever the failed-login lockout was off.
 *
 * include/auth.php now rechecks the account on every request. A locked
 * account keeps its open sessions, as in 1.2.31: the failed-login lockout sets
 * the same flag, so ending them would let anyone who knows a username log
 * that user out. The shipped include/auth.php and lib/auth.php run through the
 * auth entry probe.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function account_state_user(array $override = array()) : array {
	return $override + array('id' => '42', 'username' => 'alice', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'hash', 'must_change_password' => '', 'password_change' => 'on');
}

function account_state_request(array $user, array $session, array $extra = array()) : array {
	return $extra + array(
		'config'  => array('auth_method' => 1, 'guest_user' => 'guest'),
		'users'   => array($user, array('id' => '3', 'username' => 'guest', 'realm' => 0, 'enabled' => '', 'locked' => '', 'password' => '')),
		'session' => $session,
	);
}

function account_state_session(array $extra = array()) : array {
	return $extra + array(
		'sess_user_id'         => '42',
		'sess_user_credential' => hash('sha256', 'hash'),
		'sess_user_epoch'      => '0',
		'sess_last_activity'   => time() - 60,
	);
}

function account_state_ended(array $result) : void {
	expect($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['events'])->toContain('session_destroy')
		->and($result['events'])->toContain('login_page')
		->and($result['page_continued'])->toBeFalse();
}

test('a session for an enabled, unlocked account continues', function () {
	$result = cacti_test_run_auth_entry_probe(account_state_request(account_state_user(), account_state_session()));

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['events'])->not->toContain('session_destroy')
		->and($result['page_continued'])->toBeTrue();
});

test('a session for an account an administrator disabled ends on its next request', function () {
	account_state_ended(cacti_test_run_auth_entry_probe(account_state_request(account_state_user(array('enabled' => '')), account_state_session())));
});

test('a session keeps working after failed logins lock the account', function () {
	$request = account_state_request(account_state_user(array('locked' => 'on', 'failed_attempts' => 5)), account_state_session());
	$request['config']['secpass_lockfailed'] = '5';

	$result = cacti_test_run_auth_entry_probe($request);

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['events'])->toBe(array())
		->and($result['page_continued'])->toBeTrue();
});

test('a session for a deleted account ends on its next request', function () {
	$request          = account_state_request(account_state_user(), account_state_session());
	$request['users'] = array($request['users'][1]);

	account_state_ended(cacti_test_run_auth_entry_probe($request));
});

test('a guest session keeps working while the guest account is locked', function () {
	$request = account_state_request(account_state_user(), array('sess_user_id' => '3'), array('guest_account' => true));
	$request['users'][1]['locked'] = 'on';

	$result = cacti_test_run_auth_entry_probe($request);

	expect($result['session']['sess_user_id'] ?? null)->toBe('3')
		->and($result['events'])->not->toContain('session_destroy')
		->and($result['page_continued'])->toBeTrue();
});

test('logout everywhere ends a session bound to the previous counter', function () {
	$result = cacti_test_run_auth_entry_probe(account_state_request(account_state_user(), account_state_session(), array(
		'settings_user' => array(array('user_id' => 42, 'name' => 'session_epoch', 'value' => '1')),
	)));

	account_state_ended($result);
});

test('the session that pressed logout everywhere continues', function () {
	$result = cacti_test_run_auth_entry_probe(account_state_request(account_state_user(), account_state_session(array('sess_user_epoch' => '1')), array(
		'settings_user' => array(array('user_id' => 42, 'name' => 'session_epoch', 'value' => '1')),
	)));

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['page_continued'])->toBeTrue();
});

test('a session from before the upgrade is bound to the current counter and continues', function () {
	$session = account_state_session();
	unset($session['sess_user_epoch']);

	$result = cacti_test_run_auth_entry_probe(account_state_request(account_state_user(), $session, array(
		'settings_user' => array(array('user_id' => 42, 'name' => 'session_epoch', 'value' => '2')),
	)));

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['session']['sess_user_epoch'] ?? null)->toBe('2')
		->and($result['page_continued'])->toBeTrue();
});

function account_state_cookie(array $user, int $age_days, array $config = array()) : array {
	return cacti_test_run_auth_entry_probe(array(
		'config' => $config + array('auth_method' => 1, 'auth_cache_enabled' => 'on', 'secpass_lockfailed' => '0'),
		'users'  => array($user),
		'cache'  => array(array('user_id' => 42, 'token' => hash('sha512', 'valid-token'), 'hostname' => '192.0.2.10', 'age_days' => $age_days)),
		'cookie' => '42,0,valid-token',
		'call'   => array('type' => 'check_auth_cookie'),
	));
}

test('a remember-me cookie restores an unlocked account', function () {
	$result = account_state_cookie(account_state_user(), 29);

	expect($result['return'])->toBe('42')
		->and($result['events'])->toContain('cookie_set');
});

test('a remember-me cookie does not restore a locked account when the lockout is off', function () {
	$result = account_state_cookie(account_state_user(array('locked' => 'on')), 1);

	expect($result['return'])->toBeFalse()
		->and($result['events'])->not->toContain('cookie_set')
		->and($result['executed'])->toBe(array());
});

test('a login transition binds the session to the logout-everywhere counter', function () {
	$result = cacti_test_run_auth_entry_probe(array(
		'users'         => array(account_state_user()),
		'settings_user' => array(array('user_id' => 42, 'name' => 'session_epoch', 'value' => '4')),
		'call'          => array('type' => 'cacti_auth_transition', 'args' => array(42, 'login')),
	));

	expect($result['return'])->toBeTrue()
		->and($result['session']['sess_user_epoch'] ?? null)->toBe('4');
});

/*
 * The profile actions run against a SQLite copy of settings_user, so the
 * DELETE filters are evaluated by a SQL engine. MySQL's upsert has no SQLite
 * form; the stub applies it the way the server does for this key.
 */
function account_state_profile(string $call, array $rows) : array {
	$root    = dirname(__DIR__, 4);
	$auth    = file_get_contents($root . '/lib/auth.php');
	$profile = file_get_contents($root . '/auth_profile.php');
	$source  = '';

	foreach (array('auth_session_epoch', 'auth_session_epoch_advance') as $name) {
		$source .= cacti_test_function_source($auth, $name) . "\n";
	}

	foreach (array('api_auth_logout_everywhere', 'api_auth_clear_user_settings', 'api_auth_clear_user_setting') as $name) {
		$source .= cacti_test_function_source($profile, $name) . "\n";
	}

	$prelude = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);
$settings_user = array();

$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec("CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT, PRIMARY KEY (user_id, name))");
$pdo->exec("CREATE TABLE user_auth_cache (user_id INTEGER, token TEXT)");
$pdo->exec("INSERT INTO user_auth_cache (user_id, token) VALUES (42, 'a'), (7, 'b')");

foreach ($scenario['rows'] as $row) {
	$pdo->prepare('INSERT INTO settings_user (user_id, name, value) VALUES (?, ?, ?)')->execute($row);
}

$_SESSION = array('sess_user_id' => 42, 'sess_user_epoch' => '0');
$_REQUEST = array('tab' => 'general');

function db_execute_prepared($sql, $params = array()) {
	global $pdo;

	if (strpos($sql, 'ON DUPLICATE KEY UPDATE value = CAST(value AS UNSIGNED) + 1') !== false) {
		$sql = str_replace('ON DUPLICATE KEY UPDATE value = CAST(value AS UNSIGNED) + 1', 'ON CONFLICT (user_id, name) DO UPDATE SET value = CAST(value AS INTEGER) + 1', $sql);
	}

	return $pdo->prepare($sql)->execute($params);
}

function db_fetch_cell_prepared($sql, $params = array()) {
	global $pdo;

	$statement = $pdo->prepare($sql);
	$statement->execute($params);

	return $statement->fetchColumn();
}

function isset_request_var($name) { return isset($_REQUEST[$name]); }
function get_nfilter_request_var($name) { return $_REQUEST[$name] ?? ''; }
function kill_session_var($name) { unset($_SESSION[$name]); }
function raise_message($id) {}
function read_config_option($name) { return ''; }
function api_plugin_hook($name) {}
function api_plugin_hook_function($name, $args = null) {}

PHP;

	$tail = <<<'PHP'

$call = $scenario['call'];
$call[0](...$call[1]);

print json_encode(array(
	'rows'     => $pdo->query('SELECT user_id, name, value FROM settings_user ORDER BY name, user_id')->fetchAll(PDO::FETCH_NUM),
	'session'  => $_SESSION,
	'tokens'   => $pdo->query('SELECT user_id FROM user_auth_cache')->fetchAll(PDO::FETCH_COLUMN),
));
PHP;

	$calls = array(
		'logout_everywhere' => array('api_auth_logout_everywhere', array()),
		'clear_all'         => array('api_auth_clear_user_settings', array()),
		'clear_epoch'       => array('api_auth_clear_user_setting', array('session_epoch')),
	);

	return cacti_test_run_php_source($prelude . $source . $tail, array('rows' => $rows, 'call' => $calls[$call]));
}

test('logout everywhere advances the counter and keeps the session that pressed it', function () {
	$first  = account_state_profile('logout_everywhere', array());
	$second = account_state_profile('logout_everywhere', array(array(42, 'session_epoch', '1')));

	expect($first['rows'])->toBe(array(array(42, 'session_epoch', '1')))
		->and($first['session']['sess_user_epoch'])->toBe('1')
		->and($first['tokens'])->toBe(array(7))
		->and($second['rows'])->toBe(array(array(42, 'session_epoch', '2')))
		->and($second['session']['sess_user_epoch'])->toBe('2');
})->skip(!extension_loaded('pdo_sqlite'), 'pdo_sqlite is not loaded');

test('clearing the profile settings keeps the logout-everywhere counter', function () {
	$rows = array(array(42, 'session_epoch', '3'), array(42, 'show_graph_title', 'on'), array(7, 'show_graph_title', 'on'));

	expect(account_state_profile('clear_all', $rows)['rows'])->toBe(array(array(42, 'session_epoch', '3'), array(7, 'show_graph_title', 'on')))
		->and(account_state_profile('clear_epoch', $rows)['rows'])->toBe(array(array(42, 'session_epoch', '3'), array(7, 'show_graph_title', 'on'), array(42, 'show_graph_title', 'on')));
})->skip(!extension_loaded('pdo_sqlite'), 'pdo_sqlite is not loaded');

/*
 * user_copy() runs against SQLite copies of the tables it touches, so the
 * settings_user filters are evaluated by a SQL engine.
 */
function account_state_user_copy(bool $overwrite) : array {
	$root   = dirname(__DIR__, 4);
	$source = cacti_test_function_source(file_get_contents($root . '/lib/auth.php'), 'user_copy');

	$prelude = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec("CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, realm INTEGER, password TEXT, full_name TEXT, email_address TEXT, must_change_password TEXT, enabled TEXT)");
$pdo->exec("CREATE TABLE user_auth_perms (user_id INTEGER, item_id INTEGER, type INTEGER)");
$pdo->exec("CREATE TABLE user_auth_realm (realm_id INTEGER, user_id INTEGER)");
$pdo->exec("CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT, PRIMARY KEY (user_id, name))");
$pdo->exec("CREATE TABLE settings_tree (user_id INTEGER, graph_tree_item_id INTEGER, status INTEGER)");
$pdo->exec("CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)");
$pdo->exec("INSERT INTO user_auth VALUES (10, 'template', 0, 't', 'Template', '', '', 'on'), (42, 'bob', 0, 'b', 'Bob', '', '', 'on')");
$pdo->exec("INSERT INTO settings_user VALUES (10, 'session_epoch', '7'), (10, 'show_graph_title', 'on'), (42, 'session_epoch', '3'), (42, 'default_view_mode', '2')");

function input_validate_input_number($value) {}
function raise_message($id, $message = '', $level = 0) {}
function cacti_log($message, $output = false, $environ = '', $level = 0) {}
function api_plugin_hook_function($name, $args = null) { return $args; }
function compat_password_hash($password, $algo) { return 'placeholder'; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }

function db_fetch_row_prepared($sql, $params = array()) {
	global $pdo;

	$statement = $pdo->prepare($sql);
	$statement->execute($params);
	$row = $statement->fetch(PDO::FETCH_ASSOC);

	return $row === false ? array() : $row;
}

function db_fetch_assoc_prepared($sql, $params = array()) {
	global $pdo;

	$statement = $pdo->prepare($sql);
	$statement->execute($params);

	return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function db_execute_prepared($sql, $params = array()) {
	global $pdo;

	return $pdo->prepare($sql)->execute($params);
}

function sql_save($row, $table, $keys = 'id', $autoinc = true) {
	global $pdo;

	if ($table == 'user_auth' && empty($row['id'])) {
		unset($row['id']);
	}

	$pdo->prepare('INSERT OR REPLACE INTO ' . $table . ' (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));

	return $row['id'] ?? (int) $pdo->lastInsertId();
}

PHP;

	$tail = <<<'PHP'

$id = user_copy('template', $scenario['target'], 0, 0, $scenario['overwrite']);

print json_encode(array(
	'id'   => $id,
	'rows' => $pdo->query('SELECT user_id, name, value FROM settings_user ORDER BY user_id, name')->fetchAll(PDO::FETCH_NUM),
));
PHP;

	return cacti_test_run_php_source($prelude . $source . $tail, array('target' => $overwrite ? 'bob' : 'carol', 'overwrite' => $overwrite));
}

test('copying a template over a user keeps that user\'s logout-everywhere counter', function () {
	$result = account_state_user_copy(true);

	expect($result['id'])->toBe(42)
		->and($result['rows'])->toBe(array(
			array(10, 'session_epoch', '7'),
			array(10, 'show_graph_title', 'on'),
			array(42, 'session_epoch', '3'),
			array(42, 'show_graph_title', 'on'),
		));
})->skip(!extension_loaded('pdo_sqlite'), 'pdo_sqlite is not loaded');

test('a user copied from a template does not inherit its logout-everywhere counter', function () {
	$result = account_state_user_copy(false);

	expect($result['id'])->toBe(43)
		->and($result['rows'])->toBe(array(
			array(10, 'session_epoch', '7'),
			array(10, 'show_graph_title', 'on'),
			array(42, 'default_view_mode', '2'),
			array(42, 'session_epoch', '3'),
			array(43, 'show_graph_title', 'on'),
		));
})->skip(!extension_loaded('pdo_sqlite'), 'pdo_sqlite is not loaded');
