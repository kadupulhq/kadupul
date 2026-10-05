<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace BoostSchedulingTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
foreach (['boost_interval_seconds', 'boost_time_to_run'] as $function) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/poller_boost.php'), $function));
}

function read_config_option($name)
{
    return $GLOBALS['boost_scheduling_config'][$name] ?? null;
}

function set_config_option($name, $value)
{
    $GLOBALS['boost_scheduling_writes'][] = [$name, $value];
    $GLOBALS['boost_scheduling_config'][$name] = $value;
}

function boost_debug($message) {}

function boost_get_total_rows()
{
    return $GLOBALS['boost_scheduling_rows'];
}

function db_fetch_cell($sql)
{
    $GLOBALS['boost_scheduling_sql'][] = $sql;

    return $GLOBALS['boost_scheduling_pollers'];
}

beforeEach(function () {
    $GLOBALS['boost_scheduling_config'] = array(
        'boost_rrd_update_enable' => '',
        'boost_rrd_update_system_enable' => '',
        'boost_rrd_update_interval' => 60,
        'boost_rrd_update_max_records' => 500000,
    );
    $GLOBALS['boost_scheduling_rows'] = 0;
    $GLOBALS['boost_scheduling_pollers'] = 0;
    $GLOBALS['boost_scheduling_sql'] = array();
    $GLOBALS['boost_scheduling_writes'] = [];
});

test('the multi-collector guard queries the shipped poller table and enables system Boost', function () {
    $GLOBALS['boost_scheduling_pollers'] = '2';

    expect(boost_time_to_run(false, 10000, 9000, 0))->toBeFalse()
        ->and($GLOBALS['boost_scheduling_sql'])->toBe(array('SELECT COUNT(*) FROM poller WHERE disabled = ""'))
        ->and($GLOBALS['boost_scheduling_config']['boost_rrd_update_system_enable'])->toBe('on');
});

test('a failed collector count preserves the existing system Boost setting', function () {
    $GLOBALS['boost_scheduling_pollers'] = false;
    $GLOBALS['boost_scheduling_config']['boost_rrd_update_system_enable'] = 'on';

    expect(boost_time_to_run(false, 10000, 9000, 0))->toBeFalse()
        ->and($GLOBALS['boost_scheduling_config']['boost_rrd_update_system_enable'])->toBe('on');
});

test('an unset Boost interval falls back to 120 minutes consistently', function () {
    $GLOBALS['boost_scheduling_config']['boost_rrd_update_enable'] = 'on';
    $GLOBALS['boost_scheduling_config']['boost_rrd_update_interval'] = 0;

    expect(boost_time_to_run(false, 200, 1, 0))->toBeFalse()
        ->and($GLOBALS['boost_scheduling_config']['boost_rrd_update_interval'])->toBe(120);
});

test('a configured interval is converted from minutes to seconds for due checks', function () {
    $GLOBALS['boost_scheduling_config']['boost_rrd_update_enable'] = 'on';

    expect(boost_time_to_run(false, 3600, 1, 0))->toBeFalse()
        ->and(boost_time_to_run(false, 3601, 1, 0))->toBeTrue();
});

test('the row threshold still schedules Boost before the timer expires', function () {
    $GLOBALS['boost_scheduling_config']['boost_rrd_update_enable'] = 'on';
    $GLOBALS['boost_scheduling_rows'] = 500001;

    expect(boost_time_to_run(false, 200, 1, 0))->toBeTrue();
});

test('a forced run still schedules Boost when normal updates are disabled', function () {
    expect(boost_time_to_run(true, 10000, 9000, 0))->toBeTrue()
        ->and($GLOBALS['boost_scheduling_sql'])->toBeEmpty();
});


test('zero or one active collector disables an enabled system exactly once', function ($pollers, $enabled) {
    $GLOBALS['boost_scheduling_pollers'] = $pollers;
    $GLOBALS['boost_scheduling_config']['boost_rrd_update_system_enable'] = $enabled;
    expect(boost_time_to_run(false, 10000, 9000, 0))->toBeFalse();
    expect($GLOBALS['boost_scheduling_config']['boost_rrd_update_system_enable'])->toBe('');
    expect($GLOBALS['boost_scheduling_writes'])->toBe($enabled === 'on' ? [['boost_rrd_update_system_enable', '']] : []);
})->with([0, 1])->with(['', 'on']);

test('invalid minute intervals persist a bounded two-hour schedule at the exact deadline', function ($invalid) {
    $GLOBALS['boost_scheduling_config']['boost_rrd_update_enable'] = 'on';
    $GLOBALS['boost_scheduling_config']['boost_rrd_update_interval'] = $invalid;
    expect(boost_interval_seconds())->toBe(7200);
    expect(boost_time_to_run(false, 8199, 1000, 0))->toBeFalse();
    expect(boost_time_to_run(false, 8200, 1000, 0))->toBeTrue();
    expect($GLOBALS['boost_scheduling_config']['boost_next_run_time'])->toBe(8200);
    expect(array_values(array_filter($GLOBALS['boost_scheduling_writes'], static fn($entry) => $entry[0] === 'boost_rrd_update_interval')))->toBe([['boost_rrd_update_interval', 120]]);
})->with([null, '', 'abc', -5, 0, '1e999', '1.5', (string) (intdiv(PHP_INT_MAX, 60) + 1)]);


test('the upper integer minute boundary stays integral after conversion', function () {
    $GLOBALS['boost_scheduling_config']['boost_rrd_update_interval'] = (string) intdiv(PHP_INT_MAX, 60);
    expect(boost_interval_seconds())->toBe(intdiv(PHP_INT_MAX, 60) * 60);
    expect($GLOBALS['boost_scheduling_writes'])->toBe([]);
});
