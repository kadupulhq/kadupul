<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace PollerBatchLeaseTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 4) . '/lib/rrd_maintenance.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php'), 'process_poller_output_batch'));
function rrd_init($output, $local, $acknowledged)
{
    expect(array($output, $local, $acknowledged))->toBe(array(true, false, true));
    return $GLOBALS['batch_mode'] === 'init-failed' ? false : \rrd_maintenance_acquire(false, false, 0);
}
function rrd_close($lease)
{
    \rrd_maintenance_release($lease);
}
function process_poller_output(&$lease, $final)
{
    expect(\rrd_maintenance_acquire(true, false, 0))->toBeFalse();
    expect($final)->toBeTrue();
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
    try {
        $deferred = false;
        if ($mode === 'exception') {
            expect(fn() => process_poller_output_batch(true, $deferred))->toThrow(\RuntimeException::class, 'drain failed');
        } else {
            expect(process_poller_output_batch(true, $deferred))->toBe(in_array($mode, array('init-failed', 'retry'), true) ? 0 : 3);
            expect($deferred)->toBe($mode !== 'success');
        }
        $exclusive = \rrd_maintenance_acquire(true, false, 0);
        expect(is_resource($exclusive))->toBeTrue();
        \rrd_maintenance_release($exclusive);
    } finally {
        $GLOBALS['config'] = $saved;
        rmdir($directory);
    }
})->with(array('success', 'retry', 'init-failed', 'exception'));
