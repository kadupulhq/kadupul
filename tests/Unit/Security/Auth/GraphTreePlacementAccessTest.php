<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace GraphTreePlacementAccessTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

final class Fixture {
	public static \PDO $db;
	public static bool $admin = false;
	public static array $visible = array(1, 2);
	public static array $request = array();
	public static array $placed = array();
	public static array $messages = array();
}
final class Redirect extends \RuntimeException {}
const MESSAGE_LEVEL_ERROR = 3;
const TREE_ITEM_TYPE_GRAPH = 2;

function cacti_authorize_is_admin($user) { return Fixture::$admin; }
function is_tree_allowed($id) { return in_array((int) $id, Fixture::$visible, true); }
function db_fetch_cell_prepared($sql, $parameters = array()) {
	$query = Fixture::$db->prepare($sql);
	$query->execute($parameters);
	return $query->fetchColumn();
}
function db_fetch_assoc($sql) { return Fixture::$db->query($sql)->fetchAll(\PDO::FETCH_ASSOC); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_count($value) { return count($value); }
function get_request_var($name) { return Fixture::$request[$name] ?? ''; }
function get_filter_request_var($name, ...$args) { return get_request_var($name); }
function get_nfilter_request_var($name) { return get_request_var($name); }
function isset_request_var($name) { return array_key_exists($name, Fixture::$request); }
function sanitize_unserialize_selected_items($value) { return $value; }
function __($text) { return $text; }
function __esc($text, ...$args) { return sprintf($text, ...$args); }
function raise_message($id, ...$args) { Fixture::$messages[] = $id; }
function header($value): never { throw new Redirect($value); }
function api_tree_item_save(...$args) { Fixture::$placed[] = array($args[1], $args[3], $args[5]); }
function snmpagent_graphs_action_bottom($args) {}
function api_plugin_hook_function($name, $args) {}

foreach (array('lib/auth.php' => array('cacti_authorize_resource'), 'graphs.php' => array('graph_tree_placement_is_allowed', 'add_tree_names_to_actions_array', 'form_actions')) as $file => $functions) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
	if ($source === false) { throw new \RuntimeException('Cannot read ' . $file); }
	foreach ($functions as $function) {
		if ($function === 'graph_tree_placement_is_allowed' && !str_contains($source, 'function ' . $function . '(')) { continue; }
		eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $function));
	}
}

$previous = array();
beforeEach(function () use (&$previous) {
	foreach (array('_SESSION', 'graph_actions') as $key) { $previous[$key] = array(array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null); }
	$_SESSION = array('sess_user_id' => 5);
	$GLOBALS['graph_actions'] = array();
	Fixture::$db = new \PDO('sqlite::memory:', null, null, array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION));
	Fixture::$db->exec("CREATE TABLE graph_tree (id INTEGER, name TEXT, user_id INTEGER); INSERT INTO graph_tree VALUES (1, 'owned', 5), (2, 'other', 6)");
	Fixture::$admin = false;
	Fixture::$visible = array(1, 2);
	Fixture::$placed = Fixture::$messages = array();
	Fixture::$request = array('drp_action' => 'tr_2', 'tree_id' => '2', 'tree_item_id' => '20', 'selected_items' => array(100, 101));
});
afterEach(function () use (&$previous) {
	foreach ($previous as $key => [$exists, $value]) {
		if ($exists) { $GLOBALS[$key] = $value; } else { unset($GLOBALS[$key]); }
	}
	$previous = array();
});

function submit(): void {
	try { form_actions(); } catch (Redirect $redirect) { expect($redirect->getMessage())->toBe('Location: graphs.php?header=false'); }
}

test('a visible tree owned by another user refuses graph placement', function () {
	submit();
	expect(Fixture::$placed)->toBe(array())->and(Fixture::$messages)->not->toBeEmpty();
});

test('the owner can place graphs on an accessible tree', function () {
	Fixture::$request['drp_action'] = 'tr_1';
	Fixture::$request['tree_id'] = '1';
	submit();
	expect(Fixture::$placed)->toBe(array(array('1', '20', 100), array('1', '20', 101)));
});

test('an administrator can place graphs on another accessible tree', function () {
	Fixture::$admin = true;
	submit();
	expect(Fixture::$placed)->toHaveCount(2);
});

test('a tree hidden by access policy is refused even for its owner', function () {
	Fixture::$request['tree_id'] = '1';
	Fixture::$visible = array(2);
	submit();
	expect(Fixture::$placed)->toBe(array());
});

test('the placement menu offers only trees the actor can modify', function () {
	add_tree_names_to_actions_array();
	expect($GLOBALS['graph_actions'])->toBe(array('tr_1' => 'Place on a Tree (owned)'));
});
