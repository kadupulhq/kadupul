<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The GPRINT Presets page states that a preset used by a Graph or Graph Template
 * cannot be deleted, and offers a checkbox only for the rest. The delete applies
 * the same rule, so a forged selection cannot leave graph items naming a preset
 * that is gone.
 */

namespace GprintPresetInUseDeleteTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/**
 * Runs the delete branch in a child, with presets 2 and 4 in use.
 *
 * @param array<int, int> $selected The preset ids posted.
 *
 * @return array<string, mixed> The ids deleted and the message raised.
 */
function run_delete(array $selected, $lookupFails = false) {
	$root   = dirname(__DIR__, 4);
	$source = file_get_contents($root . '/gprint_presets.php');

	$functions = '';
	foreach (array('gprint_deletable', 'form_actions') as $name) {
		$functions .= test_php_function_source($source, $name) . "\n";
	}

	$probe = '<?php
$selected = ' . var_export($selected, true) . ';
$in_use = array(2, 4);
$axis_use = array(9);
$lookup_fails = ' . var_export($lookupFails, true) . ';
$deleted = array();
$message = null;
$gprint_actions = array();
define("MESSAGE_LEVEL_WARN", 2);
function __($text, ...$args) { return $text; }
function isset_request_var($name) { return $name === "selected_items"; }
function get_request_var($name) { return $name === "drp_action" ? "1" : ""; }
function get_nfilter_request_var($name, $default = "") { return $name === "drp_action" ? "1" : $GLOBALS["selected"]; }
function get_filter_request_var($name, $filter = null, $options = array()) { return "1"; }
function sanitize_unserialize_selected_items($items) { return $items; }
function cacti_sizeof($items) { return count($items); }
function raise_message($id, $text = "", $level = 0) { $GLOBALS["message"] = $id; }
function array_to_sql_or($ids, $column) { return $column . " IN (" . implode(",", $ids) . ")"; }
function array_rekey($rows, $key, $value) { return array_column($rows, $value, $key); }
function db_fetch_assoc($sql) {
	if ($GLOBALS["lookup_fails"]) { return false; }
	$source = strpos($sql, "right_axis_format") !== false ? $GLOBALS["axis_use"] : $GLOBALS["in_use"];
	$rows = array();
	foreach ($source as $id) {
		if (strpos($sql, (string) $id) !== false) { $rows[] = array("gprint_id" => $id); }
	}
	return $rows;
}
function db_execute($sql) {
	$GLOBALS["atomic"] = strpos($sql, "NOT EXISTS") !== false;
	preg_match("/IN \(([0-9,]*)\)/", $sql, $matches);
	$GLOBALS["deleted"] = $matches[1] === "" ? array() : array_map("intval", explode(",", $matches[1]));
	return true;
}
$atomic = false;
register_shutdown_function(function () { echo json_encode(array("deleted" => $GLOBALS["deleted"], "message" => $GLOBALS["message"], "atomic" => $GLOBALS["atomic"])); });
' . $functions . 'form_actions();
';

	$file = tempnam(sys_get_temp_dir(), 'gprint-delete-');
	file_put_contents($file, $probe);

	try {
		return json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
	} finally {
		unlink($file);
	}
}

test('the delete repeats the in-use check itself', function () {
	expect(run_delete(array(1, 3))['atomic'])->toBeTrue();
});

test('an unused preset is still deleted', function () {
	expect(run_delete(array(1, 3)))->toMatchArray(array('deleted' => array(1, 3), 'message' => null));
});

test('a preset a graph or template uses is kept', function () {
	expect(run_delete(array(2)))->toMatchArray(array('deleted' => array(), 'message' => 'gprint_in_use'));
});

test('a mixed selection deletes only the unused presets', function () {
	expect(run_delete(array(1, 2, 3, 4)))->toMatchArray(array('deleted' => array(1, 3), 'message' => 'gprint_in_use'));
});

test('a non-canonical id cannot slip past the in-use check', function () {
	// MySQL matches '007' to 7, so the check has to compare the same value.
	expect(run_delete(array('007', '2', '0002')))->toMatchArray(array('deleted' => array(7), 'message' => 'gprint_in_use'));
});

test('a preset used only as a right axis format is kept', function () {
	expect(run_delete(array(1, 9)))->toMatchArray(array('deleted' => array(1), 'message' => 'gprint_in_use'));
});

test('a numeric id that is not an integer is refused', function () {
	// MySQL would not match '3.9' to id 3, so the delete must not either.
	expect(run_delete(array('3.9', '3x', '')))->toMatchArray(array('deleted' => array(), 'message' => 'gprint_in_use'));
});

test('a failed lookup deletes nothing rather than everything', function () {
	// db_fetch_assoc returns false on a SQL error, which must not read as "none in use".
	expect(run_delete(array(1, 3), true))->toMatchArray(array('deleted' => array(), 'message' => 'gprint_in_use'));
});
