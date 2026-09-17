<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace PollerBatchLeaseTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 4) . '/lib/rrd_maintenance.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php'), 'process_poller_output_batch'));
$GLOBALS['batch_failure_logs'] = array();
function cacti_log($message, ...$args)
{
    $GLOBALS['batch_failure_logs'][] = $message;
}
function db_fetch_cell($sql)
{
    expect($sql)->toBe('SELECT COUNT(*) FROM poller_output');
    return $GLOBALS['batch_mode'] === 'empty' ? 0 : ($GLOBALS['batch_mode'] === 'query-failed' ? false : 3);
}
function rrd_init($output, $local, $acknowledged)
{
    $GLOBALS['batch_opened'] = true;
    expect(array($output, $local, $acknowledged))->toBe(array(true, false, true));
    return $GLOBALS['batch_mode'] === 'init-failed' ? false : \rrd_maintenance_acquire(false, false, 0);
}
function rrd_close($lease)
{
    \rrd_maintenance_release($lease);
}
function process_poller_output(&$lease)
{
    expect(\rrd_maintenance_acquire(true, false, 0))->toBeFalse();
    if ($GLOBALS['batch_mode'] === 'exception') {
        throw new \RuntimeException('drain failed');
    }
    return $GLOBALS['batch_mode'] === 'retry' ? false : 3;
}
test('batch writer lease is released before the next collector wait even on failure', function ($mode) {
    $saved = $GLOBALS['config'] ?? null;
    $directory = sys_get_temp_dir() . '/poller-batch-lease-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $GLOBALS['config'] = array('cacti_server_os' => 'unix', 'rra_path' => $directory);
    $GLOBALS['batch_mode'] = $mode;
    $GLOBALS['batch_opened'] = false;
    try {
        $deferred = false;
        if ($mode === 'exception') {
            expect(fn() => process_poller_output_batch($deferred))->toThrow(\RuntimeException::class, 'drain failed');
        } else {
            expect(process_poller_output_batch($deferred))->toBe(in_array($mode, array('init-failed', 'retry', 'empty', 'query-failed'), true) ? 0 : 3);
            expect($deferred)->toBe(!in_array($mode, array('success', 'empty'), true));
        }
        expect($GLOBALS['batch_opened'])->toBe(!in_array($mode, array('empty', 'query-failed'), true));
        if (in_array($mode, array('init-failed', 'query-failed'), true)) {
            $message = $mode === 'init-failed' ? 'Unable to start the RRD batch writer' : 'Unable to read pending poller output count';
            $logs = implode("\n", $GLOBALS['batch_failure_logs']);
            expect(substr_count($logs, $message))->toBe(1);
            process_poller_output_batch($deferred);
            expect(implode("\n", $GLOBALS['batch_failure_logs']))->toBe($logs);
        }
        $exclusive = \rrd_maintenance_acquire(true, false, 0);
        expect(is_resource($exclusive))->toBeTrue();
        \rrd_maintenance_release($exclusive);
    } finally {
        $GLOBALS['config'] = $saved;
        rmdir($directory);
    }
})->with(array('success', 'retry', 'init-failed', 'exception', 'empty', 'query-failed'));
