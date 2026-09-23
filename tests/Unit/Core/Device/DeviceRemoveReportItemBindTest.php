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
 * reports_items.host_id is an integer column, but the delete bound the LIKE
 * pattern belonging to the poller_command line below it. MySQL compared it as
 * a number after a truncation warning; a database that does not coerce would
 * have matched nothing and left the report items behind.
 */

namespace DeviceRemoveReportItemBindTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/**
 * Runs one remove function and returns the values it bound, keyed by table.
 *
 * @param string $handler The function to call, with its argument.
 *
 * @return array<string, mixed> The bound value of each prepared delete.
 */
function run_remove($handler) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/api_device.php');

	$functions = '';
	foreach (array('api_device_remove', 'api_device_remove_multi') as $name) {
		$functions .= test_php_function_source($source, $name) . "\n";
	}

	$probe = '<?php
$config = array();
$bound = array();
function db_fetch_cell_prepared($sql, $params) { return 1; }
function db_fetch_assoc($sql) { return array(array("id" => 5, "poller_id" => 1)); }
function db_fetch_assoc_prepared($sql, $params = array()) { return array(); }
function db_execute($sql, $log = true, $conn = false) { return true; }
function db_execute_prepared($sql, $params = array()) {
	if (preg_match("/DELETE FROM ([a-z_]+)/", $sql, $matches)) {
		$GLOBALS["bound"][$matches[1]] = $params[0];
	}
	return true;
}
function api_plugin_hook_function($name, $data = null) { return $data; }
function api_device_purge_from_remote(...$args) {}
function api_data_source_remove_multi(...$args) {}
function api_graph_remove_multi(...$args) {}
function get_remote_poller_ids_from_devices($devices) { return array(); }
function cacti_sizeof($items) { return is_array($items) ? count($items) : 0; }
function cacti_count($items) { return is_array($items) ? count($items) : 0; }
function array_to_sql_or($ids, $column) { return $column . " IN (" . implode(",", $ids) . ")"; }
function array_rekey($rows, $key, $value) { return array_column($rows, $value, $key); }
function cacti_log(...$args) {}
function raise_message(...$args) {}
function read_config_option($name) { return ""; }
function remote_poller_up($id) { return false; }
function poller_push_to_remote_db_connect(...$args) { return false; }
function input_validate_input_number(...$args) {}
function set_config_option(...$args) {}
function api_device_cache_crc_update(...$args) {}
function api_device_purge_deleted_devices(...$args) {}
register_shutdown_function(function () { echo json_encode($GLOBALS["bound"]); });
' . $functions . $handler . ';
';

	$file = tempnam(sys_get_temp_dir(), 'device-remove-');
	file_put_contents($file, $probe);

	try {
		return json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1'), true);
	} finally {
		unlink($file);
	}
}

test('removing one device binds the device id to the report items delete', function () {
	$bound = run_remove('api_device_remove(5)');

	expect($bound['reports_items'])->toBe(5)
		->and($bound['host_graph'])->toBe(5);
});

test('the poller command delete keeps its LIKE pattern', function () {
	$bound = run_remove('api_device_remove(5)');

	expect($bound['poller_command'])->toBe('5:%');
});
