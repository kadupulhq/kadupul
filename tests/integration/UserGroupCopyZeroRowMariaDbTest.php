<?php
/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Runs the real user_group_copy() and the graph settings save against tables
 * built from cacti.sql through the unchanged lib/database.php, so the insert
 * id and affected row count come from the server, not from a stub. A source
 * group deleted between the existence check and the write must not hand its
 * realms, permissions or settings to another group (#748).
 */

namespace UserGroupCopyZeroRowTest;

$root = dirname(__DIR__, 2);

require_once $root . '/lib/database.php';
require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

/* lib/database.php calls these from the global namespace */
foreach (array('cacti_sizeof', 'cacti_count', 'clean_up_lines') as $name) {
	if (!function_exists($name)) {
		eval(\test_php_function_source(file_get_contents($root . '/lib/functions.php'), $name)); // nosemgrep: php.lang.security.eval-use.eval-use
	}
}

if (!function_exists('cacti_log')) {
	eval('function cacti_log($message, $output = false, $environ = "CMDPHP", $level = "") {}'); // nosemgrep: php.lang.security.eval-use.eval-use
}

if (!function_exists('cacti_debug_backtrace')) {
	eval('function cacti_debug_backtrace($entry = "", $html = false, $record = true, $limit = 0, $skip = 0) {}'); // nosemgrep: php.lang.security.eval-use.eval-use
}

foreach (array('POLLER_VERBOSITY_NONE' => 1, 'POLLER_VERBOSITY_LOW' => 2, 'POLLER_VERBOSITY_MEDIUM' => 3, 'POLLER_VERBOSITY_HIGH' => 4, 'POLLER_VERBOSITY_DEBUG' => 5) as $constant => $value) {
	if (!defined($constant)) {
		define($constant, $value);
	}
}

$source = file_get_contents($root . '/user_group_admin.php');

foreach (array('user_group_copy', 'user_group_login_opts', 'is_user_group_realm_allowed') as $name) {
	eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($source, $name)); // nosemgrep: php.lang.security.eval-use.eval-use
}

/* the real check under another name, so a concurrent delete can land right after it */
eval('namespace ' . __NAMESPACE__ . '; ' . str_replace('function user_group_exists(', 'function real_user_group_exists(', \test_php_function_source($source, 'user_group_exists'))); // nosemgrep: php.lang.security.eval-use.eval-use

/* both handlers end every branch with exit; returning instead keeps the test process */
foreach (array('form_save', 'form_actions') as $name) {
	eval('namespace ' . __NAMESPACE__ . '; ' . str_replace('exit;', 'return;', \test_php_function_source($source, $name))); // nosemgrep: php.lang.security.eval-use.eval-use
}

const TABLES = array('user_auth_group', 'user_auth_group_perms', 'user_auth_group_realm', 'settings_user_group');

function user_group_exists($id) {
	$exists = real_user_group_exists($id);

	if (isset($GLOBALS['group_copy_after_check'])) {
		($GLOBALS['group_copy_after_check'])($id);
	}

	return $exists;
}

function user_group_refuse($id) {
	throw new \RuntimeException('refused group ' . $id);
}

function raise_message($id, $message = '', $level = 0) {
	$GLOBALS['group_copy_messages'][] = $id;
}

function get_filter_request_var($name) {
	return $GLOBALS['group_copy_request'][$name] ?? 0;
}

function get_request_var($name) {
	return $GLOBALS['group_copy_request'][$name] ?? 0;
}

function get_nfilter_request_var($name, $default = '') {
	return $GLOBALS['group_copy_request'][$name] ?? $default;
}

function isset_request_var($name) {
	return isset($GLOBALS['group_copy_request'][$name]);
}

function kill_session_var($name) {
}

function sanitize_unserialize_selected_items($items) {
	return $items;
}

function reset_group_perms($id) {
}

function api_plugin_hook($name) {
}

function connect() {
	global $database_hostname, $database_port, $database_default, $database_sessions, $config;

	$host   = getenv('BOOST_DB_SOCKET') ?: (getenv('BOOST_DB_HOST') ?: '127.0.0.1');
	$port   = getenv('BOOST_DB_PORT') ?: '3306';
	$schema = getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract';

	if (isset($database_sessions["$host:$port:$schema"])) {
		return;
	}

	$config = array('poller_id' => 1);

	/* db_connect_real() relaxes sql_mode through the connection these globals name, as config.php sets them before it connects */
	$database_hostname = $host;
	$database_port     = $port;
	$database_default  = $schema;

	if (!db_connect_real($host, getenv('BOOST_DB_USER') ?: 'root', getenv('BOOST_DB_PASSWORD') ?: '', $schema, 'mysql', $port, 1)) {
		throw new \RuntimeException('Cannot connect to the group copy contract schema');
	}
}

function drop_tables() {
	foreach (TABLES as $table) {
		db_execute('DROP TABLE IF EXISTS `' . $table . '`');
	}
}

/** @return array<string, array<int, array<string, mixed>>> */
function rows() {
	$rows = array();

	foreach (TABLES as $table) {
		$rows[$table] = db_fetch_assoc('SELECT * FROM `' . $table . '` ORDER BY 1, 2');
	}

	return $rows;
}

beforeEach(function () {
	connect();
	drop_tables();

	$schema = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');

	foreach (TABLES as $table) {
		expect(preg_match('/^CREATE TABLE `?' . $table . '`? \(.*?^\)[^;]*;/ms', $schema, $create))->toBe(1);
		expect(db_execute(rtrim($create[0], ';')))->toBeTrue();
	}

	/* group 5 is the source: a console landing page, one permission, one realm other than the console */
	db_execute("INSERT INTO user_auth_group (id, name, description, login_opts) VALUES (5, 'Source', 'copied', 2)");
	db_execute('INSERT INTO user_auth_group_perms (group_id, item_id, type) VALUES (5, 4, 1)');
	db_execute('INSERT INTO user_auth_group_realm (group_id, realm_id) VALUES (5, 7)');

	/* an unrelated group created last, so LAST_INSERT_ID() on this connection names it */
	db_execute_prepared("INSERT INTO user_auth_group (name, login_opts) VALUES ('Unrelated', 2)");
	$GLOBALS['group_copy_unrelated'] = (int) db_fetch_insert_id();

	$GLOBALS['group_copy_messages'] = array();
	$GLOBALS['group_copy_request']  = array();
	unset($GLOBALS['group_copy_after_check']);
});

afterEach(function () {
	unset($GLOBALS['group_copy_after_check']);
	drop_tables();
});

test('a copy creates the group with the permissions, realms and landing page of its source', function () {
	$copied = user_group_copy(5, 'Copy');
	$copy = (int) db_fetch_cell_prepared('SELECT MAX(id) FROM user_auth_group');
	$rows = rows();

	/* the name carries a per-request counter, so only its prefix is fixed */
	expect($copy)->toBeGreaterThan($GLOBALS['group_copy_unrelated']);
	expect(db_fetch_row_prepared("SELECT name LIKE 'Copy %' AS named, description, login_opts FROM user_auth_group WHERE id = ?", array($copy)))
		->toBe(array('named' => 1, 'description' => 'copied', 'login_opts' => 3));
	expect(array_map('intval', array_column($rows['user_auth_group_perms'], 'group_id')))->toBe(array(5, $copy));
	expect(array_map('intval', array_column($rows['user_auth_group_realm'], 'group_id')))->toBe(array(5, $copy));
	expect(array_column($rows['user_auth_group_realm'], 'realm_id'))->toBe(array(7, 7));
	expect($copied)->toBeTrue();
});

/*
 * The parent goes first while its children remain, as user_group_remove()
 * leaves them mid-delete, and the connection then creates an unrelated group,
 * so the statement before the copy left a non-zero insert id behind.
 */
function delete_source_then_insert_unrelated($id) {
	db_execute_prepared('DELETE FROM user_auth_group WHERE id = ?', array($id));
	db_execute_prepared("INSERT INTO user_auth_group (name, login_opts) VALUES ('Unrelated', 2)");

	$GLOBALS['group_copy_unrelated'] = (int) db_fetch_insert_id();
	$GLOBALS['group_copy_before']    = rows();
}

test('a copy whose source was deleted after the check copies nothing and is reported as failed', function () {
	$GLOBALS['group_copy_after_check'] = __NAMESPACE__ . '\\delete_source_then_insert_unrelated';
	$GLOBALS['group_copy_request']     = array('selected_items' => array(5), 'drp_action' => '2', 'group_prefix' => 'Copy');

	form_actions();

	expect($GLOBALS['group_copy_unrelated'])->toBeGreaterThan(0);
	expect(rows())->toBe($GLOBALS['group_copy_before']);
	expect(db_fetch_cell_prepared('SELECT login_opts FROM user_auth_group WHERE id = ?', array($GLOBALS['group_copy_unrelated'])))->toBe(2);
	expect($GLOBALS['group_copy_messages'])->toBe(array(2));

	/* LAST_INSERT_ID() on this connection still names the unrelated group */
	expect((int) db_fetch_cell('SELECT LAST_INSERT_ID()'))->toBe($GLOBALS['group_copy_unrelated']);
});

test('the copy itself returns false when its insert adds no row', function () {
	delete_source_then_insert_unrelated(5);

	expect(user_group_copy(5, 'Copy'))->toBeFalse();
	expect(rows())->toBe($GLOBALS['group_copy_before']);
});

test('a copy that succeeds raises no failure message', function () {
	$GLOBALS['group_copy_request'] = array('selected_items' => array(5), 'drp_action' => '2', 'group_prefix' => 'Copy');

	form_actions();

	expect($GLOBALS['group_copy_messages'])->toBe(array());
	expect(db_fetch_cell("SELECT COUNT(*) FROM user_auth_group WHERE name LIKE 'Copy %'"))->toBe(1);
});

test('graph settings are not stored for a group deleted after the existence check', function () {
	$GLOBALS['settings_user']      = array('general' => array('default_view_mode' => array(), 'timespan' => array('items' => array('default_timespan' => array()))));
	$GLOBALS['group_copy_request'] = array('id' => 5, 'save_component_graph_settings' => 1, 'default_view_mode' => '1', 'default_timespan' => '7');

	form_save();

	expect(db_fetch_assoc('SELECT group_id, name, value FROM settings_user_group ORDER BY name'))->toBe(array(
		array('group_id' => 5, 'name' => 'default_timespan', 'value' => '7'),
		array('group_id' => 5, 'name' => 'default_view_mode', 'value' => '1'),
	));

	db_execute('DELETE FROM settings_user_group');

	$GLOBALS['group_copy_after_check'] = function ($id) {
		db_execute_prepared('DELETE FROM user_auth_group WHERE id = ?', array($id));
	};

	form_save();

	expect(db_fetch_assoc('SELECT * FROM settings_user_group'))->toBe(array());
});
