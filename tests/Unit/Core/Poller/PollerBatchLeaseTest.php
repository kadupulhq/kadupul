<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace PollerBatchLeaseTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 4) . '/lib/rrd_maintenance.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php'), 'process_poller_output_batch'));
const SQL_NO_CACHE = '';
function remote_backend()
{
    return ($GLOBALS['config']['force_storage_location_local'] ?? false) !== true && $GLOBALS['batch']['proxy'];
}
function read_config_option($key)
{
    return $GLOBALS['batch']['proxy'];
}
function cacti_log($message, ...$args)
{
    $GLOBALS['batch']['logs'][] = $message;
}
function db_fetch_cell_prepared($sql)
{
    expect($sql)->toBe('SELECT  COUNT(*) FROM poller_output');
    return $GLOBALS['batch']['mode'] === 'empty' ? 0 : ($GLOBALS['batch']['mode'] === 'query-failed' ? false : 3);
}
function rrd_init($output, $exclusive, $acknowledged, $timeout = null, &$busy = null)
{
    expect(array($output, $exclusive, $acknowledged))->toBe(array(true, false, true));
    $GLOBALS['batch']['opens']++;
    $busy = false;
    if ($GLOBALS['batch']['mode'] === 'init-failed') {
        return false;
    }
    if (remote_backend()) {
        return fopen('php://temp', 'r+');
    }
    expect($timeout)->toBe(0);
    return \rrd_maintenance_acquire(false, false, 0, $busy);
}
function rrd_close($pipe)
{
    $GLOBALS['batch']['closes']++;
    \rrd_maintenance_release($pipe);
}
function process_poller_output(&$pipe)
{
    if (!remote_backend()) {
        expect(\rrd_maintenance_acquire(true, false, 0))->toBeFalse();
    }
    if ($GLOBALS['batch']['mode'] === 'exception') {
        throw new \RuntimeException('drain failed');
    }
    return $GLOBALS['batch']['mode'] === 'retry' ? false : 3;
}
beforeEach(function () {
    $this->savedConfig = $GLOBALS['config'] ?? null;
    $this->directory = sys_get_temp_dir() . '/poller-batch-lease-' . bin2hex(random_bytes(8));
    mkdir($this->directory, 0700);
    $GLOBALS['config'] = array('cacti_server_os' => 'unix', 'rra_path' => $this->directory);
    $GLOBALS['batch'] = array('mode' => 'empty', 'proxy' => false, 'opens' => 0, 'closes' => 0, 'logs' => array());
    $proxy = false;
    process_poller_output_batch($deferred, $proxy); // Reset dedupe after recovery.
});
afterEach(function () {
    $GLOBALS['config'] = $this->savedConfig;
    rmdir($this->directory);
});

test('local batches release leases and defer busy maintenance without errors or delays', function ($mode) {
    $GLOBALS['batch']['mode'] = $mode;
    $held = $mode === 'busy' ? \rrd_maintenance_acquire(true, false, 0) : false;
    $proxy = false;
    try {
        $start = microtime(true);
        if ($mode === 'exception') {
            expect(function () use (&$deferred, &$proxy) {
                return process_poller_output_batch($deferred, $proxy);
            })->toThrow(\RuntimeException::class, 'drain failed');
        } else {
            expect(process_poller_output_batch($deferred, $proxy))->toBe($mode === 'success' ? 3 : 0);
            expect($deferred)->toBe(in_array($mode, array('retry', 'init-failed', 'query-failed'), true));
        }
        if ($mode === 'busy') {
            expect(microtime(true) - $start)->toBeLessThan(1.0);
            expect(implode(' ', $GLOBALS['batch']['logs']))->toContain('NOTE: RRD maintenance is active')->not->toContain('ERROR');
        }
        if (in_array($mode, array('empty', 'query-failed'), true)) {
            expect($GLOBALS['batch']['opens'])->toBe(0);
        }
    } finally {
        \rrd_maintenance_release($held);
    }
    $exclusive = \rrd_maintenance_acquire(true, false, 0);
    expect(is_resource($exclusive))->toBeTrue();
    \rrd_maintenance_release($exclusive);
})->with(array('success', 'retry', 'init-failed', 'exception', 'empty', 'query-failed', 'busy'));

test('batch failure logs deduplicate while failing and resume after recovery', function ($mode) {
    $proxy = false;
    $GLOBALS['batch']['mode'] = $mode;
    process_poller_output_batch($deferred, $proxy);
    process_poller_output_batch($deferred, $proxy);
    expect($GLOBALS['batch']['logs'])->toHaveCount(1);
    $GLOBALS['batch']['mode'] = 'success';
    process_poller_output_batch($deferred, $proxy);
    $GLOBALS['batch']['mode'] = $mode;
    process_poller_output_batch($deferred, $proxy);
    expect($GLOBALS['batch']['logs'])->toHaveCount(2);
})->with(array('init-failed', 'query-failed'));

test('proxy batches reuse a connection until failure or the collector cycle ends', function ($failure) {
    $GLOBALS['batch']['proxy'] = true;
    $GLOBALS['batch']['mode'] = 'success';
    $proxy = false;
    process_poller_output_batch($deferred, $proxy);
    process_poller_output_batch($deferred, $proxy);
    expect($GLOBALS['batch']['opens'])->toBe(1)->and($GLOBALS['batch']['closes'])->toBe(0);
    $GLOBALS['batch']['mode'] = $failure;
    if ($failure === 'exception') {
        expect(function () use (&$deferred, &$proxy) {
            return process_poller_output_batch($deferred, $proxy);
        })->toThrow(\RuntimeException::class);
    } else {
        expect(process_poller_output_batch($deferred, $proxy))->toBe(0)->and($deferred)->toBeTrue();
    }
    expect($GLOBALS['batch']['closes'])->toBe(1)->and($proxy)->toBeFalse();
    $GLOBALS['batch']['mode'] = 'success';
    process_poller_output_batch($deferred, $proxy);
    expect($GLOBALS['batch']['opens'])->toBe(2);
    rrd_close($proxy);
})->with(array('retry', 'exception'));

test('forced local storage ignores the configured proxy and Windows boolean writers close safely', function ($windows) {
    $GLOBALS['batch']['proxy'] = true;
    $GLOBALS['batch']['mode'] = 'success';
    $GLOBALS['config']['force_storage_location_local'] = true;
    $GLOBALS['config']['cacti_server_os'] = $windows ? 'win32' : 'unix';
    $proxy = false;
    expect(process_poller_output_batch($deferred, $proxy))->toBe(3)->and($deferred)->toBeFalse();
    expect($GLOBALS['batch']['opens'])->toBe(1)->and($GLOBALS['batch']['closes'])->toBe(1)->and($proxy)->toBeFalse();
})->with(array(false, true));
