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
 * cacti_authorize_resource() lets a Reports Administration user (realm 21)
 * change any report, and cacti_authorize_has_realm() counted that realm from
 * every group the user belongs to. is_realm_allowed(), which guards the pages,
 * counts a group only while it is enabled, so a user holding the realm only
 * through a disabled group could still save, move or open another user's report
 * items.
 *
 * The helpers and is_realm_allowed() come from lib/auth.php and the handlers
 * from lib/html_reports.php. Their SQL runs as written against an in-memory
 * SQLite copy of the realm, group and report tables; only the request, redirect
 * and message calls are stubbed. The stubs live in this namespace because other
 * unit tests load lib/functions.php into the global namespace.
 */

namespace ReportAdminGroupRealmTest;

use PDO;

$root = dirname(__DIR__, 4);

preg_match_all("/^define\('((?:REPORTS|MESSAGE_LEVEL|POLLER_VERBOSITY)_[A-Z0-9_]+)',\s*([0-9]+)\);/m", file_get_contents($root . '/include/global_constants.php'), $constants, PREG_SET_ORDER);

foreach ($constants as $constant) {
	if (!defined($constant[1])) {
		define($constant[1], (int) $constant[2]);
	}
}

if (!function_exists(__NAMESPACE__ . '\cacti_authorize_resource')) {
	$code    = '';
	$sources = array(
		'lib/auth.php'         => array('cacti_authorize_resource', 'cacti_authorize_has_realm', 'cacti_authorize_is_admin', 'is_realm_allowed'),
		'lib/html_reports.php' => array('reports_form_save', 'reports_address_malformed', 'reports_from_allowed', 'reports_item_movedown', 'reports_item_moveup', 'reports_item_edit'),
	);

	// release/1.2.31 and lts/1.2 have no address or From check
	$optional = array('reports_address_malformed', 'reports_from_allowed');

	foreach ($sources as $file => $fns) {
		$src = file_get_contents($root . '/' . $file);

		foreach ($fns as $fn) {
			preg_match('/^function ' . $fn . '\(.*?^}\n/ms', $src, $match);

			if (empty($match) && in_array($fn, $optional, true)) {
				continue;
			}

			expect($match)->not->toBeEmpty();

			$code .= $match[0];
		}
	}

	// each exit follows a recorded redirect, and would otherwise end the run
	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . str_replace('exit;', 'return;', $code));
}

/* the realm, group and report tables as cacti.sql defines the columns used */
function realm_database() {
	$db = new PDO('sqlite::memory:');
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$db->exec("CREATE TABLE user_auth_realm (realm_id INTEGER NOT NULL DEFAULT 0, user_id INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (realm_id, user_id))");
	$db->exec("CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled CHAR(2) NOT NULL DEFAULT 'on')");
	$db->exec("CREATE TABLE user_auth_group_members (group_id INTEGER NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (group_id, user_id))");
	$db->exec("CREATE TABLE user_auth_group_realm (group_id INTEGER NOT NULL, realm_id INTEGER NOT NULL, PRIMARY KEY (group_id, realm_id))");
	$db->exec("CREATE TABLE reports (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL DEFAULT 0)");
	$db->exec("CREATE TABLE reports_items (id INTEGER PRIMARY KEY, report_id INTEGER NOT NULL DEFAULT 0, sequence INTEGER NOT NULL DEFAULT 0)");

	// user 5 owns report 7 and its items 70 and 71
	$db->exec("INSERT INTO reports VALUES (7, 5)");
	$db->exec("INSERT INTO reports_items VALUES (70, 7, 1), (71, 7, 2)");

	// group 1 is disabled and group 2 enabled; both grant Reports Administration
	$db->exec("INSERT INTO user_auth_group VALUES (1, ''), (2, 'on')");
	$db->exec("INSERT INTO user_auth_group_realm VALUES (1, 21), (2, 21)");

	// 11 only through the disabled group, 12 through the enabled group, 13 on
	// their own account, 14 through both groups, 16 not at all
	$db->exec("INSERT INTO user_auth_group_members VALUES (1, 11), (2, 12), (1, 14), (2, 14)");
	$db->exec("INSERT INTO user_auth_realm VALUES (21, 13)");

	return $db;
}

/* lib/database.php returns false for no row and each value as a string */
function db_fetch_cell_prepared($sql, $params = array(), $col_name = '', $log = true, $db_conn = false) {
	$query = $GLOBALS['rg_db']->prepare($sql);
	$query->execute(array_values($params));

	$value = $query->fetchColumn();

	return ($value === false || $value === null ? false : (string) $value);
}

/* reports_item_edit() goes on to build the form, so stop once the row loads */
function db_fetch_row_prepared($sql, $params = array()) {
	$GLOBALS['rg_loaded'][] = array_map('intval', $params);

	throw new \RuntimeException('item row loaded');
}

function db_table_exists($table, $log = true, $db_conn = false) {
	$query = $GLOBALS['rg_db']->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
	$query->execute(array($table));

	return ($query->fetchColumn() !== false);
}

function read_config_option($name) {
	return ($name == 'auth_method' ? '1' : '');
}

function cacti_version_compare($version1, $version2, $operator = '>') {
	return version_compare($version1, $version2, $operator);
}

function isset_request_var($name) {
	return isset($GLOBALS['rg_request'][$name]);
}

function isempty_request_var($name) {
	return (!isset($GLOBALS['rg_request'][$name]) || $GLOBALS['rg_request'][$name] == '');
}

function get_nfilter_request_var($name, $default = '') {
	return $GLOBALS['rg_request'][$name] ?? $default;
}

function get_request_var($name, $default = '') {
	return $GLOBALS['rg_request'][$name] ?? $default;
}

function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array()) {
	return $GLOBALS['rg_request'][$name] ?? '';
}

/* lib/functions.php form_input_validate() without its logging */
function form_input_validate($field_value, $field_name, $regexp_match, $allow_nulls, $custom_message = 3) {
	if ($allow_nulls == true && $field_value == '') {
		return $field_value;
	}

	if ($allow_nulls == false && $field_value == '') {
		raise_message($custom_message);

		$_SESSION['sess_error_fields'][$field_name] = $field_name;
	} elseif ($regexp_match != '' && !preg_match('/' . $regexp_match . '/', $field_value)) {
		raise_message($custom_message);

		$_SESSION['sess_error_fields'][$field_name] = $field_name;
	}

	return $field_value;
}

function raise_message($message_id, $message = '', $message_level = 0) {
	$GLOBALS['rg_messages'][] = $message_id;
}

function is_error_message() {
	return (isset($_SESSION['sess_error_fields']) && count($_SESSION['sess_error_fields']));
}

function sql_save($save, $table_name) {
	$GLOBALS['rg_saved'][] = $table_name;

	return (int) $save['id'];
}

function reports_item_resequence($report_id) {
}

function get_reports_page() {
	return 'reports_user.php';
}

function header($header) {
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function move_item_down($table, $id, $where) {
	$GLOBALS['rg_moves'][] = array('down', $id);
}

function move_item_up($table, $id, $where) {
	$GLOBALS['rg_moves'][] = array('up', $id);
}

function as_user($user_id, $request) {
	$_SESSION = array('sess_user_id' => $user_id);

	$GLOBALS['rg_request'] = $request;
}

function save_item_as($user_id) {
	as_user($user_id, array(
		'save_component_report_item' => '1',
		'report_id'      => '7',
		'id'             => '70',
		'sequence'       => '1',
		'item_type'      => '1',
		'local_graph_id' => '12',
		'timespan'       => '7',
		'align'          => '1',
		'font_size'      => '10',
	));

	reports_form_save();
}

function move_items_as($user_id) {
	as_user($user_id, array('item_id' => '70', 'id' => '7'));
	reports_item_movedown();

	as_user($user_id, array('item_id' => '71', 'id' => '7'));
	reports_item_moveup();
}

function edit_item_as($user_id) {
	as_user($user_id, array('id' => '7', 'item_id' => '70'));

	try {
		reports_item_edit();
	} catch (\RuntimeException $e) {
	}
}

beforeEach(function () {
	$GLOBALS['config']      = array('cacti_db_version' => '1.2.31');
	$GLOBALS['rg_db']       = realm_database();
	$GLOBALS['rg_request']  = array();
	$GLOBALS['rg_messages'] = array();
	$GLOBALS['rg_saved']    = array();
	$GLOBALS['rg_moves']    = array();
	$GLOBALS['rg_loaded']   = array();
});

dataset('Reports Administration that counts', array(
	'through an enabled group'             => array(12),
	'on the user\'s own account'           => array(13),
	'through an enabled and disabled group' => array(14),
));

test('Reports Administration from an enabled group or the account changes another user\'s item', function ($user_id) {
	save_item_as($user_id);
	move_items_as($user_id);
	edit_item_as($user_id);

	expect($GLOBALS['rg_saved'])->toBe(array('reports_items'));
	expect($GLOBALS['rg_moves'])->toBe(array(array('down', '70'), array('up', '71')));
	expect($GLOBALS['rg_loaded'])->toBe(array(array(70)));
	expect($GLOBALS['rg_messages'])->toBe(array('reports_item_save'));
})->with('Reports Administration that counts');

test('Reports Administration only through a disabled group changes nothing', function () {
	save_item_as(11);
	move_items_as(11);
	edit_item_as(11);

	expect($GLOBALS['rg_saved'])->toBe(array());
	expect($GLOBALS['rg_moves'])->toBe(array());
	expect($GLOBALS['rg_loaded'])->toBe(array());
	expect($GLOBALS['rg_messages'])->toBe(array('permission_denied', 'permission_denied'));
});

dataset('realm holders', array(
	'only through a disabled group'         => array(11, false),
	'through an enabled group'              => array(12, true),
	'on the user\'s own account'            => array(13, true),
	'through an enabled and disabled group' => array(14, true),
	'not at all'                            => array(16, false),
));

test('cacti_authorize_has_realm() answers as is_realm_allowed() does', function ($user_id, $expected) {
	expect(is_realm_allowed(21, $user_id))->toBe($expected);
	expect(cacti_authorize_has_realm($user_id, 21))->toBe($expected);
})->with('realm holders');

dataset('databases without every group table', array(
	'no group tables'        => array(array()),
	'no user_auth_group'     => array(array('user_auth_group_members', 'user_auth_group_realm')),
	'no group realm table'   => array(array('user_auth_group', 'user_auth_group_members')),
	'no group members table' => array(array('user_auth_group', 'user_auth_group_realm')),
));

test('cacti_authorize_has_realm() counts only the account realm while a group table is missing', function ($tables) {
	// include/auth.php joins the group tables only once all three exist
	$schema = array(
		'user_auth_group'         => "CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled CHAR(2) NOT NULL DEFAULT 'on')",
		'user_auth_group_members' => "CREATE TABLE user_auth_group_members (group_id INTEGER NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (group_id, user_id))",
		'user_auth_group_realm'   => "CREATE TABLE user_auth_group_realm (group_id INTEGER NOT NULL, realm_id INTEGER NOT NULL, PRIMARY KEY (group_id, realm_id))",
	);

	static $user_id = 100;

	// cacti_authorize_has_realm() caches each user and realm for the request
	$group_user   = ++$user_id;
	$account_user = ++$user_id;
	$no_user      = ++$user_id;

	$db = new PDO('sqlite::memory:');
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->exec("CREATE TABLE user_auth_realm (realm_id INTEGER NOT NULL DEFAULT 0, user_id INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (realm_id, user_id))");
	$db->exec("INSERT INTO user_auth_realm VALUES (21, $account_user)");

	foreach ($tables as $table) {
		$db->exec($schema[$table]);
	}

	if (in_array('user_auth_group', $tables, true)) {
		$db->exec("INSERT INTO user_auth_group VALUES (2, 'on')");
	}

	if (in_array('user_auth_group_members', $tables, true)) {
		$db->exec("INSERT INTO user_auth_group_members VALUES (2, $group_user)");
	}

	if (in_array('user_auth_group_realm', $tables, true)) {
		$db->exec("INSERT INTO user_auth_group_realm VALUES (2, 21)");
	}

	$GLOBALS['rg_db'] = $db;

	expect(cacti_authorize_has_realm($account_user, 21))->toBeTrue();
	expect(cacti_authorize_has_realm($group_user, 21))->toBeFalse();
	expect(cacti_authorize_has_realm($no_user, 21))->toBeFalse();
})->with('databases without every group table');
