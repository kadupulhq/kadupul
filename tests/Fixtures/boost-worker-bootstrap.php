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
$config = array('base_path' => $fixture);
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
    return count($value);
}
function cacti_log(...$args) {}
function boost_debug(...$args) {}
function read_config_option($key)
{
    if ($key === 'path_php_binary') {
        return PHP_BINARY;
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
register_shutdown_function(function () use ($fixture, $mode) {
    $GLOBALS['debug'] = true;
    $children = boost_launch_children();
    $pids = array_column($children, 'pid');
    if ($mode === 'shutdown') {
        file_put_contents($fixture . '/result.json', json_encode(array(null, $pids)));
        return;
    }
    if ($mode === 'launch-failure') {
        $children[] = array('process' => false, 'child' => 3, 'pid' => 0);
    }
    $start = hrtime(true);
    $result = boost_wait_children($children, 1);
    $write = boost_process_output(1, array(array(1700000060, 42)), 'fixture.rrd', array('value' => true), false);
    file_put_contents($fixture . '/result.json', json_encode(array($result, $pids, (hrtime(true) - $start) / 1000000000, $write)));
});
