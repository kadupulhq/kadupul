<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

list(, $root, $directory, $mode, $connection, $collect) = $argv;
if ($collect === '1') {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require __DIR__ . '/rrd-process-coverage.php';
}
$config = array('poller_id' => $connection === 'local' ? 1 : 2, 'connection' => $connection);
$remote_db_cnn_id = 'primary-database';
$writes = array();
function read_config_option($key)
{
    return in_array($key, array('boost_rrd_update_enable', 'boost_rrd_update_system_enable'), true) ? 'on' : '';
}
function set_config_option(...$args) {}
function cacti_sizeof($value)
{
    return count($value);
}
function db_fetch_row($sql)
{
    return array('Value' => strpos($GLOBALS['mode'], 'split-') === 0 ? 180 : 1000);
}
function db_execute($sql, $log, $connection)
{
    $GLOBALS['writes'][] = array('sql' => $sql, 'connection' => $connection);
    return !str_ends_with($GLOBALS['mode'], 'failure');
}
require $root . '/lib/boost.php';
$handler = static function () {
    throw new RuntimeException('Unexpected caller warning');
};
set_error_handler($handler);
$samples = array(
    array('local_data_id' => 1, 'rrd_name' => 'value', 'time' => '2026-01-01 00:00:00', 'output' => '42'),
    array('local_data_id' => 2, 'rrd_name' => 'value', 'time' => '2026-01-01 00:00:00', 'output' => '43'),
);
$original = $samples;
$result = boost_poller_on_demand($samples);
$restored = set_error_handler($handler) === $handler;
restore_error_handler();
echo json_encode(array('result' => $result, 'writes' => $writes,
    'handler_restored' => $restored, 'samples_unchanged' => $samples === $original), JSON_THROW_ON_ERROR);
