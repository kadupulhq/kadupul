<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Group mutations must name a group that exists, and a group without console
 * access cannot keep the console as its landing page. The edit page enforces
 * the second rule in JavaScript only; the save handlers now enforce both.
 */

namespace UserGroupTargetAuthorizationTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/**
 * Runs one handler in a child, because a refusal exits.
 *
 * @param string               $handler The handler to call.
 * @param array<string, mixed> $request The request variables, also used as POST.
 * @param array<string, mixed> $db      Existing group ids, console realm ids and stored login options.
 *
 * @return array<string, mixed> The writes made and the message raised.
 */
function run_handler($handler, array $request, array $db) {
	$root   = dirname(__DIR__, 4);
	$source = file_get_contents($root . '/user_group_admin.php');

	$functions = '';
	foreach (array('user_group_exists', 'user_group_refuse', 'user_group_login_opts', 'is_user_group_realm_allowed', 'update_policies', 'perm_remove', 'form_actions', 'form_save') as $name) {
		$functions .= test_php_function_source($source, $name) . "\n";
	}

	$probe = '<?php
$request = ' . var_export($request, true) . ';
$_POST = $request;
$db = ' . var_export($db, true) . ';
$writes = array();
$message = null;
$settings_user = array();
$group_actions = array();
$user_auth_realms = array();
function isset_request_var($name) { return isset($GLOBALS["request"][$name]); }
function get_filter_request_var($name) { return $GLOBALS["request"][$name] ?? 0; }
function get_request_var($name) { return $GLOBALS["request"][$name] ?? 0; }
function get_nfilter_request_var($name, $default = "") { return $GLOBALS["request"][$name] ?? $default; }
function sanitize_unserialize_selected_items($items) { return $items; }
function csrf_require_post($strict) {}
function get_client_addr() { return "192.0.2.1"; }
function cacti_log(...$args) {}
function raise_message($name) { if ($GLOBALS["message"] === null) { $GLOBALS["message"] = $name; } }
function reset_group_perms($id) {}
function cacti_count($x) { return count($x); }
function user_group_enable($id) { $GLOBALS["writes"][] = "ENABLE " . $id; }
function db_execute_prepared($sql, $params = array()) { $GLOBALS["writes"][] = strtok(trim($sql), " "); return true; }
function db_fetch_cell_prepared($sql, $params) {
	$db = $GLOBALS["db"];
	if (strpos($sql, "FROM user_auth_group_realm") !== false) {
		return (int) in_array($params[0], $db["console"]);
	}
	if (strpos($sql, "SELECT login_opts") !== false) {
		return $db["login_opts"][$params[0]] ?? false;
	}
	return (int) in_array($params[0], $db["groups"]);
}
register_shutdown_function(function () { echo json_encode(array("writes" => $GLOBALS["writes"], "message" => $GLOBALS["message"])); });
' . $functions . $handler . '();
';

	$file = tempnam(sys_get_temp_dir(), 'group-target-');
	file_put_contents($file, $probe);

	try {
		// header() is a no-op on the CLI, so the shutdown output is all that prints.
		return json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
	} finally {
		unlink($file);
	}
}

$db = array(
	'groups'     => array(5, 6),
	'console'    => array(6),
	'login_opts' => array(5 => '2', 6 => '2'),
);

$refused = array('writes' => array(), 'message' => 'permission_denied');

test('policies change only on a group that exists', function () use ($db, $refused) {
	expect(run_handler('update_policies', array('id' => 5, 'policy_graphs' => 2), $db))->toBe(array('writes' => array('UPDATE'), 'message' => null));
	expect(run_handler('update_policies', array('id' => 404, 'policy_graphs' => 2), $db))->toBe($refused);
});

test('a permission is removed only from a group that exists', function () use ($db, $refused) {
	expect(run_handler('perm_remove', array('id' => 9, 'group_id' => 5, 'type' => 'graph'), $db))->toBe(array('writes' => array('DELETE'), 'message' => null));
	expect(run_handler('perm_remove', array('id' => 9, 'group_id' => 404, 'type' => 'graph'), $db))->toBe($refused);
});

test('associations and bulk actions refuse a missing group', function () use ($db, $refused) {
	expect(run_handler('form_actions', array('id' => 404, 'associate_member' => 1, 'drp_action' => '1', 'chk_7' => 'on'), $db))->toBe($refused);
	// One forged id in the batch stops the whole batch.
	expect(run_handler('form_actions', array('selected_items' => array(5, 404), 'drp_action' => '3'), $db))->toBe($refused);
	expect(run_handler('form_actions', array('selected_items' => array(5, 6), 'drp_action' => '3'), $db))->toBe(array('writes' => array('ENABLE 5', 'ENABLE 6'), 'message' => null));
});

test('realms and graph settings are saved only for a group that exists', function () use ($db, $refused) {
	expect(run_handler('form_save', array('id' => 404, 'save_component_realm_perms' => 1, 'section8' => 'on'), $db))->toBe($refused);
	expect(run_handler('form_save', array('id' => 404, 'save_component_graph_settings' => 1), $db))->toBe($refused);
	expect(run_handler('form_save', array('id' => 404, 'save_component_group' => 1, 'name' => 'x'), $db))->toBe($refused);
});

test('the console landing page needs the console realm', function () {
	// Group 6 has the console realm; group 5 does not.
	eval('namespace ' . __NAMESPACE__ . '; function db_fetch_cell_prepared($sql, $params) { return (int) ($params[0] == 6); }');
	$source = file_get_contents(dirname(__DIR__, 4) . '/user_group_admin.php');
	foreach (array('is_user_group_realm_allowed', 'user_group_login_opts') as $name) {
		eval('namespace ' . __NAMESPACE__ . ';' . test_php_function_source($source, $name));
	}

	expect(user_group_login_opts('2', 5))->toBe('3')
		->and(user_group_login_opts('2', 0))->toBe('3')
		->and(user_group_login_opts('2', 6))->toBe('2')
		->and(user_group_login_opts('1', 5))->toBe('1');
});

test('a realm save stores the posted realms and nothing else', function () use ($db) {
	expect(run_handler('form_save', array('id' => 5, 'save_component_realm_perms' => 1, 'section7' => 'on'), $db))
		->toBe(array('writes' => array('DELETE', 'REPLACE'), 'message' => 1));
});
