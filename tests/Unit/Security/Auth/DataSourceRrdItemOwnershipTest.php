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
 * The edit page offers RRD items only on an untemplated data source, and
 * removal only while more than one item remains. The handlers enforce the same,
 * and an item must belong to the data source named in the request.
 */

namespace DataSourceRrdItemOwnershipTest;

/**
 * Runs one handler in a child, because a refusal exits.
 *
 * @param string               $handler The handler to call.
 * @param array<string, int>   $request The request variables.
 * @param array<string, mixed> $db      data_local templates by id, item owners by id, item counts by source.
 *
 * @return array<string, mixed> The writes made and the message raised.
 */
function run_handler($handler, array $request, array $db) {
	$root   = dirname(__DIR__, 4);
	$source = file_get_contents($root . '/data_sources.php');
	require_once $root . '/tests/Helpers/PhpSource.php';

	$functions = '';
	foreach (array('ds_rrd_editable', 'ds_rrd_add', 'ds_rrd_remove') as $name) {
		$functions .= test_php_function_source($source, $name) . "\n";
	}

	$probe = '<?php
$request = ' . var_export($request, true) . ';
$db = ' . var_export($db, true) . ';
$writes = array();
$message = null;
function get_filter_request_var($name) { return $GLOBALS["request"][$name] ?? 0; }
function get_request_var($name) { return $GLOBALS["request"][$name] ?? 0; }
function cacti_log(...$args) {}
function raise_message($name) { $GLOBALS["message"] = $name; }
function db_fetch_insert_id() { return 99; }
function db_execute_prepared($sql, $params) { $GLOBALS["writes"][] = strtok(trim($sql), " "); return true; }
function db_fetch_cell_prepared($sql, $params) {
	$db = $GLOBALS["db"];
	if (strpos($sql, "FROM data_local") !== false) { return $db["templates"][$params[0]] ?? false; }
	if (strpos($sql, "COUNT(*)") !== false) { return $db["counts"][$params[0]] ?? 0; }
	return $db["owners"][$params[0]] ?? false;
}
register_shutdown_function(function () { echo json_encode(array("writes" => $GLOBALS["writes"], "message" => $GLOBALS["message"])); });
' . $functions . $handler . '();
';

	$file = tempnam(sys_get_temp_dir(), 'rrd-item-');
	file_put_contents($file, $probe);

	try {
		return json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
	} finally {
		unlink($file);
	}
}

$db = array(
	'templates' => array(5 => 0, 6 => 0, 7 => 3),
	'owners'    => array(50 => 5, 60 => 6, 70 => 7),
	'counts'    => array(5 => 2, 6 => 1, 7 => 2),
);

test('an item is added only to an untemplated data source', function () use ($db) {
	expect(run_handler('ds_rrd_add', array('id' => 5), $db))->toBe(array('writes' => array('INSERT'), 'message' => null));
	expect(run_handler('ds_rrd_add', array('id' => 7), $db))->toBe(array('writes' => array(), 'message' => 'permission_denied'));
	expect(run_handler('ds_rrd_add', array('id' => 404), $db))->toBe(array('writes' => array(), 'message' => 'permission_denied'));
});

test('an item is removed only from the data source that owns it', function () use ($db) {
	expect(run_handler('ds_rrd_remove', array('id' => 50, 'local_data_id' => 5), $db))->toBe(array('writes' => array('DELETE', 'UPDATE'), 'message' => null));
	// Named under another data source: refused.
	expect(run_handler('ds_rrd_remove', array('id' => 50, 'local_data_id' => 6), $db))->toBe(array('writes' => array(), 'message' => 'permission_denied'));
});

test('the last item and items of a templated data source are kept', function () use ($db) {
	expect(run_handler('ds_rrd_remove', array('id' => 60, 'local_data_id' => 6), $db))->toBe(array('writes' => array(), 'message' => 'permission_denied'));
	expect(run_handler('ds_rrd_remove', array('id' => 70, 'local_data_id' => 7), $db))->toBe(array('writes' => array(), 'message' => 'permission_denied'));
});
