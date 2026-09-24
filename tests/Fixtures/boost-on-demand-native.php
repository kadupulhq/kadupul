<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = $argv[1];
$directory = $argv[2];
$mode = $argv[3];
$owned = $argv[4] === '1';
if ($argv[5] === '1') {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require __DIR__ . '/rrd-process-coverage.php';
}
$config = array('library_path' => $directory);
$debug = $get_memory = false;
$opened = $closed = 0;
$deletes = $updates = $messages = array();
$samples = array(
    array('local_data_id' => 42, 'data_template_id' => 1, 'timestamp' => 1699999800, 'rrd_name' => 'value', 'output' => '21'),
    array('local_data_id' => 42, 'data_template_id' => 1, 'timestamp' => 1699999860, 'rrd_name' => 'value', 'output' => '22'),
);
function cacti_system_zone_set() {}
function cacti_log($message, ...$args)
{
    $GLOBALS['messages'][] = $message;
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_count($value)
{
    return cacti_sizeof($value);
}
function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}
function read_config_option($key)
{
    return array('boost_rrd_update_max_records_per_select' => 100,
        'boost_rrd_update_string_length' => $GLOBALS['mode'] === 'first-ack' ? 1 : 1000)[$key] ?? 0;
}
function db_fetch_assoc($sql)
{
    return array(array('name' => 'poller_output_boost_arch_fixture'));
}
function db_fetch_assoc_prepared($sql, $params)
{
    if (str_contains($sql, 'SELECT po.local_data_id')) {
        return $GLOBALS['samples'];
    }
    if (str_contains($sql, 'SELECT DISTINCT data_source_name')) {
        return array(array('data_source_name' => 'value', 'rrd_name' => 'value', 'rrd_path' => $GLOBALS['directory'] . '/sample.rrd'));
    }
    if (str_contains($sql, 'gti.task_item_id IS NULL')) {
        return array();
    }
    throw new RuntimeException('Unexpected fixture read: ' . $sql);
}
function db_fetch_cell($sql)
{
    return 1700000000;
}
function db_fetch_cell_prepared($sql, $params)
{
    return 1;
}
function db_execute($sql, ...$args)
{
    return true;
}
function db_execute_prepared($sql, $params, ...$args)
{
    if (str_starts_with($sql, 'DELETE')) {
        $GLOBALS['deletes'][] = array($sql, $params);
        $failAt = array('delete-first' => 1, 'delete-later' => 2)[$GLOBALS['mode']] ?? 0;
        return count($GLOBALS['deletes']) !== $failAt;
    }
    return true;
}
function rrd_init(...$args)
{
    $GLOBALS['opened']++;
    return fopen('php://temp', 'r+');
}
function rrd_close($pipe)
{
    $GLOBALS['closed']++;
    fclose($pipe);
}
function get_rrdtool_version()
{
    return '1.8';
}
function cacti_version_compare(...$args)
{
    return version_compare(...$args);
}
function rrdtool_execute($command, ...$args)
{
    $GLOBALS['updates'][] = $command;
    return !in_array($GLOBALS['mode'], array('first-ack', 'last-ack'), true);
}
foreach (array('BOOST_TIMER_START' => 0, 'BOOST_TIMER_END' => 1, 'BOOST_TIMER_TOTAL' => 2, 'BOOST_TIMER_CYCLES' => 3,
    'POLLER_VERBOSITY_MEDIUM' => 2, 'POLLER_VERBOSITY_HIGH' => 3, 'POLLER_VERBOSITY_NONE' => 0,
    'RRDTOOL_OUTPUT_BOOLEAN' => 1) as $name => $value) {
    define($name, $value);
}
// Boost quotes the RRD path with the real helper from lib/rrd.php.
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/src/Graphing/Infrastructure/Rrd/UnrepresentableArgument.php';
require $root . '/src/Graphing/Infrastructure/Rrd/PipeEncoder.php';
foreach (array('rrdtool_pipe_encoder', 'rrdtool_pipe_quote', 'rrdtool_proxy_token_is_safe', 'rrdtool_proxy_token', 'rrdtool_command_argument', 'rrdtool_command_path', 'rrdtool_create_maximum', 'rrdtool_create_rras', 'rrdtool_create_path', 'rrdtool_create_structured_path') as $function) {
    eval(test_php_function_source(file_get_contents($root . '/lib/rrd.php'), $function));
}
require $root . '/lib/boost.php';
$handler = static function () {
    throw new RuntimeException('Unexpected caller warning');
};
set_error_handler($handler);
error_reporting(E_ALL);
$pipe = $owned ? '' : fopen('php://temp', 'r+');
$result = boost_process_poller_output(42, $pipe);
$restored = set_error_handler($handler) === $handler;
restore_error_handler();
echo json_encode(array('result' => $result, 'opened' => $opened, 'closed' => $closed,
    'borrowed_open' => $owned || is_resource($pipe), 'handler_restored' => $restored,
    'reporting_restored' => error_reporting() === E_ALL, 'deletes' => $deletes, 'updates' => $updates), JSON_THROW_ON_ERROR);
if (!$owned) {
    fclose($pipe);
}
