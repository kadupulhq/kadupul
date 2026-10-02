<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 2) . '/lib/remote_agent_auth.php';

test('remote agent identities fail closed on duplicate and unverified source addresses', function () {
    $pollers = array(array('id' => 1, 'hostname' => '192.0.2.1'), array('id' => 2, 'hostname' => 'collector.example'));
    $cache = array();
    $reverseCalls = $forwardCalls = 0;
    $reverse = static function ($address) use (&$reverseCalls) {
        $reverseCalls++;
        return 'COLLECTOR.EXAMPLE.';
    };
    $records = array(array('ip' => '192.0.2.2'));
    $forward = static function ($name) use (&$forwardCalls, &$records) {
        $forwardCalls++;
        return $records;
    };
    $fetch = static function ($key) use (&$cache) {
        return $cache[$key] ?? null;
    };
    $store = static function ($key, $id) use (&$cache) {
        $cache[$key] = $id;
    };
    $resolve = static function ($address, $rows) use ($reverse, $forward, $fetch, $store) {
        return remote_agent_resolve_poller($address, $rows, $reverse, $forward, $fetch, $store);
    };
    expect($resolve('invalid', $pollers))->toBe(0)
        ->and($resolve('192.0.2.1', array()))->toBe(0)
        ->and($resolve('192.0.2.1', $pollers))->toBe(1)
        ->and($reverseCalls)->toBe(0);
    $duplicate = $pollers;
    $duplicate[] = array('id' => 3, 'hostname' => '192.0.2.1');
    expect($resolve('192.0.2.1', $duplicate))->toBe(0);
    expect($resolve('192.0.2.2', $pollers))->toBe(2)
        ->and($resolve('192.0.2.2', $pollers))->toBe(2)
        ->and($reverseCalls)->toBe(1)->and($forwardCalls)->toBe(1);
    $pollers[1]['id'] = 3;
    expect($resolve('192.0.2.2', $pollers))->toBe(3)->and($reverseCalls)->toBe(2);
    $duplicate = $pollers;
    $duplicate[] = array('id' => 4, 'hostname' => 'collector.example');
    expect($resolve('192.0.2.2', $duplicate))->toBe(0);
    $cache = array();
    $records = array(array('ip' => '192.0.2.99'));
    expect($resolve('192.0.2.2', $pollers))->toBe(0)
        ->and($resolve('192.0.2.2', $pollers))->toBe(0);
    $cache = array_fill_keys(array_keys($cache), 999);
    $records = array(array('ip' => '192.0.2.2'));
    expect($resolve('192.0.2.2', $pollers))->toBe(3);
    expect($resolve('192.0.2.2', array(array('id' => 1, 'hostname' => '192.0.2.1'), array('id' => 4, 'hostname' => '192.0.2.4'))))->toBe(0);
    $records = false;
    $cache = array();
    expect($resolve('192.0.2.2', $pollers))->toBe(0);
    $cache = array();
    $pollers[1]['disabled'] = 'on';
    expect($resolve('192.0.2.2', $pollers))->toBe(0);
});
