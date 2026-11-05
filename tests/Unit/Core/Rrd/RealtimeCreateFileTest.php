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
 * The realtime poller takes its create definition from
 * rrdtool_function_create(..., true) and writes its own file. It hands that
 * file to rrdtool_create_file(), which creates beside the destination and
 * renames into place, rather than writing a create command of its own.
 */

namespace RealtimeCreateFileTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

// nosemgrep: php.lang.security.eval-use.eval-use -- repository source under test
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/poller_realtime.php'), 'process_poller_output_rt'));

const RRDTOOL_OUTPUT_STDOUT = 1;

function cacti_log(...$args) {}
function cacti_log_safe_value($value) { return json_encode($value); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_has_control_chars($value) { return preg_match('/[\x00-\x1F\x7F]/', (string) $value) === 1; }
function cacti_rrdtool_valid_path($path) { return !cacti_has_control_chars($path); }
function read_config_option($key) {
	return array('realtime_cache_path' => $GLOBALS['rt_dir'], 'path_rrdtool' => '/usr/bin/rrdtool', 'realtime_interval' => 5)[$key] ?? '';
}
function db_fetch_assoc_prepared(...$args) {
	return array(array('local_data_id' => 9, 'output' => '42', 'time' => '2026-09-15 00:00:00', 'rrd_name' => 'value'));
}
function db_execute_prepared(...$args) { return true; }
function get_data_source_path($id, ...$args) { return $GLOBALS['rt_dir'] . '/source.rrd'; }
function rrdtool_function_create($id, $show_source) {
	return '/usr/bin/rrdtool create' . RRD_NL . $GLOBALS['rt_dir'] . '/source.rrd' . RRD_NL . '--start 0 --step 300 ' . RRD_NL . 'DS:value:GAUGE:600:0:U' . RRD_NL;
}
function rrdtool_create_file($path, $definition, $log, $pipe, $logopt) {
	$GLOBALS['rt_created'][] = array($path, $definition);
	return '';
}
function rrdtool_execute($command, ...$args) {
	$GLOBALS['rt_commands'][] = $command;
	return '';
}
function rrdtool_function_update($updates, $pipe, &$completed) {
	$completed = array();
	return count($updates);
}

test('realtime creates its file through rrdtool_create_file with its own step', function () {
	if (!defined('RRD_NL')) {
		define('RRD_NL', " \\\n");
	}

	$saved = $GLOBALS['config'] ?? null;
	$dir   = sys_get_temp_dir() . '/rt-create-file-' . bin2hex(random_bytes(8));
	mkdir($dir, 0700);
	file_put_contents($dir . '/rrd.php', '<?php');

	$GLOBALS['rt_dir']      = $dir;
	$GLOBALS['config']      = array('library_path' => $dir);
	$GLOBALS['rt_created']  = array();
	$GLOBALS['rt_commands'] = array();

	try {
		process_poller_output_rt(true, 1, 60);

		expect($GLOBALS['rt_commands'])->toBe(array())
			->and($GLOBALS['rt_created'])->toHaveCount(1)
			->and($GLOBALS['rt_created'][0][0])->toBe($dir . '/user_1_9.rrd')
			->and($GLOBALS['rt_created'][0][1])->not->toContain($dir)
			->and($GLOBALS['rt_created'][0][1])->toContain('--step 5 ')
			->and($GLOBALS['rt_created'][0][1])->toContain('DS:value:GAUGE:600:0:U')
			->and($GLOBALS['rt_created'][0][1])->not->toContain("\n");
	} finally {
		$GLOBALS['config'] = $saved;
		unlink($dir . '/rrd.php');
		rmdir($dir);
	}
});
