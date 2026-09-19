<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

list(, $root, $directory, $mode, $collect) = $argv;
if ($collect === '1') {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require __DIR__ . '/rrd-process-coverage.php';
}
$config = array('poller_id' => $mode === 'remote' ? 2 : 1, 'connection' => 'offline', 'library_path' => $directory);
$opens = $closed = 0;
$messages = array();
$debug = $get_memory = false;
foreach (array('BOOST_TIMER_START' => 0, 'BOOST_TIMER_END' => 1, 'BOOST_TIMER_TOTAL' => 2,
    'BOOST_TIMER_CYCLES' => 3, 'POLLER_VERBOSITY_MEDIUM' => 2) as $constant => $value) {
    define($constant, $value);
}
function cacti_system_zone_set() {}
function get_rrdtool_version() { return '1.8'; }
function cacti_version_compare(...$args) { return version_compare(...$args); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function array_rekey($rows, $key, $value) { return $rows ? array_column($rows, $value, $key) : array(); }
function cacti_count($value) { return cacti_sizeof($value); }
function db_fetch_cell(...$args) { return 0; }
function db_fetch_assoc_prepared(...$args) { return array(); }
function cacti_log($message, ...$args)
{
    $GLOBALS['messages'][] = $message;
}
function read_config_option($key)
{
    return in_array($key, array('boost_rrd_update_enable', 'boost_rrd_update_system_enable'), true)
        && $GLOBALS['mode'] !== 'disabled' ? 'on' : 0;
}
function rrd_init(...$args)
{
    if (++$GLOBALS['opens'] > 1) {
        throw new RuntimeException('Writer initialization was retried after failure');
    }
    if ($GLOBALS['mode'] === 'init-exception') {
        throw new RuntimeException('Injected initialization exception');
    }
    if (in_array($GLOBALS['mode'], array('owned-exception', 'owned-empty'), true)) {
        return fopen('php://temp', 'r+');
    }
    return false;
}
function rrd_close($pipe)
{
    $GLOBALS['closed']++;
    if (!is_resource($pipe)) {
        throw new RuntimeException('Uninitialized writer was closed');
    }
    fclose($pipe);
}
function db_fetch_assoc(...$args)
{
    if (str_ends_with($GLOBALS['mode'], '-empty')) {
        return array();
    }
    throw new RuntimeException('Injected database exception');
}
require $root . '/lib/boost.php';
$handler = static function () {
    throw new RuntimeException('Unexpected caller warning');
};
set_error_handler($handler);
error_reporting(E_ALL);
$result = $exception = null;
$borrowed = str_starts_with($mode, 'borrowed-') ? fopen('php://temp', 'r+') : false;
if ($mode === 'consumer-boolean-empty') {
    $borrowed = true; // Windows synchronous writer marker.
} elseif ($mode === 'consumer-proxy-empty') {
    $borrowed = array('proxy connection', 'proxy public key');
}
try {
    $result = str_starts_with($mode, 'consumer-')
        ? boost_process_poller_output(42, $borrowed) : boost_fetch_cache_check(42, $borrowed);
} catch (Throwable $error) {
    $exception = $error->getMessage();
}
$restored = set_error_handler($handler) === $handler;
restore_error_handler();
echo json_encode(array('result' => $result, 'exception' => $exception, 'opens' => $opens, 'closed' => $closed,
    'borrowed_open' => $borrowed === false || is_resource($borrowed) || $borrowed === true || is_array($borrowed),
    'messages' => $messages, 'handler_restored' => $restored, 'reporting_restored' => error_reporting() === E_ALL), JSON_THROW_ON_ERROR);
if (is_resource($borrowed)) {
    fclose($borrowed);
}
