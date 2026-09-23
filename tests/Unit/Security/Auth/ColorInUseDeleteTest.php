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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The Colors page states that a color used by a Graph or Graph Template cannot
 * be deleted, and offers a checkbox only for the rest. The delete applies the
 * same rule, so a forged selection cannot leave graph items without a color.
 */

namespace ColorInUseDeleteTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/**
 * Runs the delete branch in a child, with colors 2 and 4 in use.
 *
 * @param array<int, int> $selected The color ids posted.
 *
 * @return array<string, mixed> The ids deleted and the message raised.
 */
function run_delete(array $selected, $lookupFails = false) {
	$root   = dirname(__DIR__, 4);
	$source = file_get_contents($root . '/color.php');

	$functions = '';
	foreach (array('color_deletable', 'form_actions') as $name) {
		$functions .= test_php_function_source($source, $name) . "\n";
	}

	$probe = '<?php
$selected = ' . var_export($selected, true) . ';
$in_use = array(2, 4);
$lookup_fails = ' . var_export($lookupFails, true) . ';
$deleted = array();
$message = null;
$color_actions = array();
define("MESSAGE_LEVEL_WARN", 2);
function __($text, ...$args) { return $text; }
function isset_request_var($name) { return $name === "selected_items"; }
function get_request_var($name) { return $name === "drp_action" ? "1" : ""; }
function get_nfilter_request_var($name, $default = "") { return $GLOBALS["selected"]; }
function get_filter_request_var($name, $filter = null, $options = array()) { return "1"; }
function sanitize_unserialize_selected_items($items) { return $items; }
function cacti_sizeof($items) { return count($items); }
function raise_message($id, $text = "", $level = 0) { $GLOBALS["message"] = $id; }
function array_to_sql_or($ids, $column) { return $column . " IN (" . implode(",", $ids) . ")"; }
function array_rekey($rows, $key, $value) { return array_column($rows, $value, $key); }
function db_fetch_assoc($sql) {
	if ($GLOBALS["lookup_fails"]) { return false; }
	$rows = array();
	foreach ($GLOBALS["in_use"] as $id) {
		if (strpos($sql, (string) $id) !== false) { $rows[] = array("color_id" => $id); }
	}
	return $rows;
}
function db_execute($sql) {
	preg_match("/IN \(([0-9,]*)\)/", $sql, $matches);
	$GLOBALS["deleted"] = $matches[1] === "" ? array() : array_map("intval", explode(",", $matches[1]));
	return true;
}
register_shutdown_function(function () { echo json_encode(array("deleted" => $GLOBALS["deleted"], "message" => $GLOBALS["message"])); });
' . $functions . 'form_actions();
';

	$file = tempnam(sys_get_temp_dir(), 'color-delete-');
	file_put_contents($file, $probe);

	try {
		return json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
	} finally {
		unlink($file);
	}
}

test('an unused color is still deleted', function () {
	expect(run_delete(array(1, 3)))->toBe(array('deleted' => array(1, 3), 'message' => null));
});

test('a color a graph or template uses is kept', function () {
	expect(run_delete(array(2)))->toBe(array('deleted' => array(), 'message' => 'color_in_use'));
});

test('a mixed selection deletes only the unused colors', function () {
	expect(run_delete(array(1, 2, 3, 4)))->toBe(array('deleted' => array(1, 3), 'message' => 'color_in_use'));
});

test('a failed lookup deletes nothing rather than everything', function () {
	// db_fetch_assoc returns false on a SQL error, which must not read as "none in use".
	expect(run_delete(array(1, 3), true))->toBe(array('deleted' => array(), 'message' => 'color_in_use'));
});
