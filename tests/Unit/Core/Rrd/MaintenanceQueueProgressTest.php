<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace MaintenanceQueueProgressTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
foreach (array(
    'lib/rrd_maintenance.php' => array('rrd_maintenance_cleanup_supported'),
    'lib/rrd.php' => array('rrd_check_path'),
    'poller_maintenance.php' => array('rrdfile_purge', 'remove_files'),
) as $file => $functions) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
    foreach ($functions as $function) {
        // test-only eval of source read from this repository, not external input
        eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $function));
    }
}
const RRDTOOL_OUTPUT_BOOLEAN = 4;
function read_config_option($key, ...$args) { return $key === 'storage_location' ? 1 : ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_log($message, ...$args) { $GLOBALS['progress_logs'][] = $message; }
function cacti_log_safe_value($value) { return $value; }
function maint_debug(...$args) {}
function set_config_option(...$args) {}
function rrd_init() { return 'proxy-pipe'; }
function rrd_close($pipe) {}
function rrd_maintenance_release($lease) {}
function rrdtool_execute(...$args) { return true; }
function cacti_rrdtool_valid_path($path) { return !preg_match('/[\x00-\x1f\x7f]/', $path); }
function rrdtool_execute_path_command($command, $path, ...$args) { return $path !== '0000.rrd'; }
function db_fetch_cell($sql) { return count($GLOBALS['progress_queue']); }
function progress_page($after) {
    if (++$GLOBALS['progress_reads'] > 3) { throw new \RuntimeException('retained requests were read again'); }
    $rows = array_values(array_filter($GLOBALS['progress_queue'], fn($row) => $after === null || strcmp($row['name'], $after) > 0));
    usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));
    return array_slice($rows, 0, 1000);
}
function db_fetch_assoc($sql) { return progress_page(null); }
function db_fetch_assoc_prepared($sql, $params = array()) {
    return strpos($sql, 'data_source_purge_action') !== false ? progress_page($params[0]) : array();
}
function db_execute_prepared($sql, $params) {
    $GLOBALS['progress_queue'] = array_values(array_filter($GLOBALS['progress_queue'], fn($row) => $row['name'] !== $params[0]));
    return true;
}

test('failed cleanup requests are retained without stalling later batches', function ($action) {
    $GLOBALS['config'] = array('cacti_server_os' => 'unix', 'rra_path' => '/rra', 'base_path' => '/cacti');
    $GLOBALS['archived'] = $GLOBALS['purged'] = 0;
    $GLOBALS['poller_start'] = microtime(true);
    $GLOBALS['progress_reads'] = 0;
    $GLOBALS['progress_logs'] = array();
    $GLOBALS['progress_queue'] = array(array('id' => 0, 'name' => "0001\n.rrd", 'local_data_id' => 0, 'action' => $action));
    for ($i = 0; $i <= 1000; $i++) {
        $GLOBALS['progress_queue'][] = array('id' => $i + 1, 'name' => sprintf('%04d.rrd', $i), 'local_data_id' => 0, 'action' => $action);
    }
    expect(rrdfile_purge(false))->toBeFalse()
        ->and($GLOBALS['progress_reads'])->toBe(2)
        ->and(array_column($GLOBALS['progress_queue'], 'name'))->toBe(array("0001\n.rrd", '0000.rrd'))
        ->and($GLOBALS[$action === '1' ? 'purged' : 'archived'])->toBe(1000)
        ->and(implode("\n", $GLOBALS['progress_logs']))->toContain('rejected invalid RRDproxy path')->toContain('0000.rrd')->toContain('RRDMAINT STATS');
})->with(array('1', '3'));
