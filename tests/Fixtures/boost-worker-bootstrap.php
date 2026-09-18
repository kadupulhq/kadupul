<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
$fixture = getenv('BOOST_FIXTURE');
$mode = getenv('BOOST_MODE');
foreach ($_SERVER['argv'] as $argument) {
    if (strpos($argument, '--child=') === 0) {
        if (in_array($mode, array('timeout', 'shutdown'), true)) {
            sleep(30);
        }
        exit($mode === 'early-crash' ? 9 : 0);
    }
}
$config = array('base_path' => $fixture, 'library_path' => $fixture . '/lib', 'poller_id' => 1, 'cacti_server_os' => 'unix', 'rra_path' => $fixture);
define('COPYRIGHT_YEARS', '2026');
define('POLLER_VERBOSITY_MEDIUM', 2);
define('BOOST_TIMER_START', 0);
define('BOOST_TIMER_END', 1);
function boost_timer(...$args) {}
function boost_rrdtool_function_update(...$args)
{
    return getenv('BOOST_MODE') === 'success' ? 'OK' : 'ERROR';
}
function get_cacti_version()
{
    return 'fixture';
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log(...$args) {}
function boost_debug(...$args) {}
function read_config_option($key)
{
    if ($key === 'boost_last_run_time' && strpos(getenv('BOOST_MODE'), 'master-') === 0) {
        return 1700000000;
    }
    if ($key === 'path_php_binary') {
        return PHP_BINARY;
    }
    if (in_array($key, array('boost_rrd_update_interval','boost_rrd_update_max_runtime','boost_rrd_update_max_records'), true)) {
        return 1;
    }
    if ($key === 'boost_parallel') {
        return 2;
    }
    if ($key === 'path_boost_log') {
        return getenv('BOOST_FIXTURE') . '/workers.log';
    }
    return '';
}
function unregister_process($type, $name, $child, $pid)
{
    file_put_contents(getenv('BOOST_FIXTURE') . '/reaped', json_encode(array($child, $pid)) . "\n", FILE_APPEND);
}
function rrd_init(...$args)
{
    return getenv('BOOST_MODE') !== 'output-init';
}
function rrd_close($pipe)
{
    $GLOBALS['closed_writer'] = true;
}
function boost_get_arch_table_names(...$args)
{
    return in_array(getenv('BOOST_MODE'), array('output-archives','prepare-failure'), true) ? array() : array('poller_output_boost_arch_pending');
}
function db_fetch_cell($sql)
{
    $mode = getenv('BOOST_MODE');
    if (strpos($sql, 'WHERE status < 0') !== false) {
        return $mode === 'master-failed-count' ? false : ($mode === 'master-child-failed' ? 1 : 0);
    }
    if (strpos($sql, 'SUM(status)') !== false) {
        return $mode === 'master-invalid-total' ? false : 5;
    }
    if (strpos($sql, 'FROM poller_output_boost_processes') !== false) {
        return $mode === 'master-missing-child' ? 1 : 2;
    }
    return 2;
}
function db_fetch_cell_prepared($sql, $params = array())
{
    if (str_contains($sql, 'SELECT ENGINE FROM information_schema.TABLES')) {
        return 'InnoDB';
    }
    $mode = getenv('BOOST_MODE');
    if ($mode === 'output-next-count' && strpos($sql, 'SELECT COUNT(*)') !== false) {
        return false;
    }
    if ($mode === 'archive-retry' && strpos($sql, 'TABLE_ROWS') !== false) {
        return 0;
    }
    if (strpos($sql, 'COUNT(at.local_data_id)') !== false) {
        return $mode === 'output-count' ? false : ($mode === 'output-empty' ? 0 : 1);
    }
    if (strpos($sql, 'MAX(local_data_id)') !== false) {
        return $mode === 'output-last' ? false : 1;
    }
    return $mode === 'output-ids' ? false : 1;
}
function get_installed_rrdtool_version()
{
    return '1.7';
}
function get_rrdtool_version(...$args)
{
    return '1.7';
}
function boost_error_handler(...$args)
{
    throw new RuntimeException('Unexpected Boost error');
}
function array_rekey($values, ...$args)
{
    return $values;
}
function db_fetch_assoc_prepared(...$args)
{
    return array();
}
function db_fetch_assoc($sql)
{
    if (getenv('BOOST_MODE') === 'output-next-count') {
        return array();
    }
    return strpos($sql, 'information_schema.tables') !== false && strpos(getenv('BOOST_MODE'), 'master-success') === 0
        ? array(array('name' => 'poller_output_boost_arch_fixture')) : false;
}
function boost_archive_is_empty($table)
{
    return getenv('BOOST_MODE') === 'master-success-empty';
}
function dsstats_boost_bottom()
{
    $GLOBALS['settings_written']['dsstats_called'] = true;
}
function rrdcheck_boost_bottom()
{
    $GLOBALS['settings_written']['rrdcheck_called'] = true;
}
function api_plugin_hook($name)
{
    $GLOBALS['settings_written']['plugin_hook'] = $name;
}
define('SQL_NO_CACHE', '');
function boost_memory_limit() {}
function boost_get_total_rows()
{
    return 1;
}
function set_config_option($key, $value)
{
    $GLOBALS['settings_written'][$key] = $value;
}
function db_fetch_row($sql)
{
    return strpos($sql, 'SHOW STATUS') === 0 || getenv('BOOST_MODE') === 'archive-retry' ? array() : array('output' => 42);
}
function register_process_start(...$args)
{
    return true;
}
function db_execute($sql)
{
    if (strpos($sql, 'DROP TABLE') !== false) {
        if (getenv('BOOST_MODE') === 'master-success-empty') {
            $GLOBALS['settings_written']['dropped_archive'] = $sql;
            return true;
        }
        throw new RuntimeException('Archive cleanup after failed preparation');
    }
    return true;
}
function db_execute_prepared(...$args)
{
    return true;
}
if ($mode === 'shutdown') {
    // Launch during normal execution, as the master does, so its registered
    // cleanup precedes the final coverage collector and owns live children.
    $debug = true;
    $children = boost_launch_children();
    file_put_contents($fixture . '/result.json', json_encode(array(null, array_column($children, 'pid'))));
    return;
}
register_shutdown_function(function () use ($fixture, $mode) {
    if (in_array($mode, array('prepare-failure','archive-retry'), true) || strpos($mode, 'master-') === 0) {
        file_put_contents($fixture . '/result.json', json_encode($GLOBALS['settings_written']));
        return;
    }

    if (strpos($mode, 'output-') === 0) {
        $GLOBALS['start'] = time();
        $GLOBALS['archive_table'] = 'pending';
        $GLOBALS['max_run_duration'] = 60;
        $result = boost_output_rrd_data(1);
        file_put_contents($fixture . '/result.json', json_encode(array($result, !empty($GLOBALS['closed_writer']))));
        return;
    }

    $GLOBALS['debug'] = true;
    $children = boost_launch_children();
    $pids = array_column($children, 'pid');
    if ($mode === 'launch-failure') {
        $children[] = array('process' => false, 'child' => 3, 'pid' => 0);
    }
    $start = hrtime(true);
    $result = boost_wait_children($children, 1);
    $write = boost_process_output(1, array(array(1700000060, 42)), 'fixture.rrd', array('value' => true), false);
    file_put_contents($fixture . '/result.json', json_encode(array($result, $pids, (hrtime(true) - $start) / 1000000000, $write)));
});
