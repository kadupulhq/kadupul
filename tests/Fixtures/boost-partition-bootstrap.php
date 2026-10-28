<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
// Stands in for include/cli_check.php + global.php under a copy of
// bin/legacy-boost-partition.php. The status "table" is one file.
require dirname(__DIR__, 2) . '/include/vendor/autoload.php';

$config = array('base_path' => getenv('BOOST_FIXTURE'));

function read_config_option($key, $force = false)
{
    return match ($key) {
        'boost_active_run' => getenv('BOOST_ACTIVE_RUN'),
        'boost_rrd_update_max_runtime' => 1,
        'path_php_binary' => PHP_BINARY,
        default => '',
    };
}

function db_fetch_cell_prepared($sql, $params = array())
{
    if ($sql !== 'SELECT status FROM poller_output_boost_processes WHERE run_id = ? AND process_handler = ?'
        || count($params) !== 2 || !is_string($params[0]) || !is_int($params[1])) {
        throw new LogicException('Unexpected status query: ' . $sql . ' ' . json_encode($params));
    }
    $file = getenv('BOOST_FIXTURE') . '/status-' . $params[0] . '-' . $params[1];

    return is_file($file) ? file_get_contents($file) : false;
}

// The processes table as the master worker reads it after an overrun; the stub master writes it.
function db_fetch_assoc_prepared($sql, $params = array())
{
    if ($sql !== 'SELECT pid, taskid FROM processes WHERE tasktype = ? AND taskname = ?' || $params !== array('boost', 'child')) {
        throw new LogicException('Unexpected processes query: ' . $sql . ' ' . json_encode($params));
    }
    $file = getenv('BOOST_FIXTURE') . '/processes';

    return is_file($file) ? json_decode(file_get_contents($file), true) : array();
}

function unregister_process($tasktype, $taskname, $taskid = 0, $pid = -1)
{
    file_put_contents(getenv('BOOST_FIXTURE') . '/unregistered', json_encode(array($tasktype, $taskname, $taskid, $pid)) . "\n", FILE_APPEND);
}
