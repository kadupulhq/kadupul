<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$fixture = getenv('BOOST_FIXTURE');
$mode = getenv('BOOST_MODE');
$config = array('base_path' => $fixture, 'library_path' => $fixture . '/lib');
$writes = array();
$updates = array();
$messages = array();
define('COPYRIGHT_YEARS', '2026');
define('BOOST_TIMER_START', 0);
define('BOOST_TIMER_END', 1);
define('SQL_NO_CACHE', '');
function get_cacti_version()
{
    return 'fixture';
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log($message, ...$args)
{
    $GLOBALS['messages'][] = $message;
}
function boost_debug(...$args) {}
function boost_timer(...$args) {}
function read_config_option($key)
{
    if ($key === 'path_boost_log') {
        return '';
    }
    return $key === 'boost_rrd_update_string_length' && $GLOBALS['mode'] === 'split-failure' ? 1 : 1000;
}
function get_installed_rrdtool_version()
{
    return '1.7';
}
function get_rrdtool_version(...$args)
{
    return '1.7';
}
function cacti_version_compare($a, $b, $operator)
{
    return version_compare($a, $b, $operator);
}
function boost_error_handler($number, $message)
{
    throw new RuntimeException($message);
}
function boost_get_arch_table_names(...$args)
{
    return $GLOBALS['mode'] === 'archive-failure' ? false : array('poller_output_boost_arch_fixture');
}
function boost_get_rrd_filename_and_template($id)
{
    return array('rrd_template' => 'value', 'rrd_path' => 'sample-' . $id . '.rrd');
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
    return array(
        array('local_data_id' => 42, 'data_template_id' => 1, 'timestamp' => '1699999800', 'rrd_name' => 'value', 'output' => '21'),
        array('local_data_id' => $GLOBALS['mode'] === 'next-id-failure' ? 43 : 42,
            'data_template_id' => 1, 'timestamp' => '1699999860', 'rrd_name' => 'value', 'output' => '22')
    );
}
function db_execute($sql)
{
    $GLOBALS['writes'][] = array($sql, array());
    return true;
}
function db_execute_prepared($sql, $params)
{
    $GLOBALS['writes'][] = array($sql, $params);
    if (str_contains($sql, 'DELETE FROM poller_output_boost_arch_')) {
        return $GLOBALS['mode'] !== 'delete-failure';
    }
    return $GLOBALS['mode'] !== 'assignment-failure';
}
function boost_rrdtool_function_update($id, $path, $template, $output, $pipe)
{
    $GLOBALS['updates'][] = array($id, $template, $output);
    // Only the first data source fails; a later one must still be written.
    return in_array($GLOBALS['mode'], array('next-id-failure', 'split-failure', 'last-failure'), true) && $id === 42 ? 'ERROR injected' : 'OK';
}
register_shutdown_function(function () use ($fixture) {
    $GLOBALS['archive_table'] = 'fixture';
    $GLOBALS['get_memory'] = false;
    $handler = function () {
        throw new RuntimeException('Unexpected warning');
    };
    set_error_handler($handler);
    $result = boost_process_local_data_ids(43, 2, false);
    $restored = set_error_handler($handler) === $handler;
    restore_error_handler();
    file_put_contents($fixture . '/result.json', json_encode(array(
        'result' => $result, 'writes' => $GLOBALS['writes'], 'updates' => $GLOBALS['updates'], 'handler_restored' => $restored,
        'messages' => $GLOBALS['messages']
    )));
});
