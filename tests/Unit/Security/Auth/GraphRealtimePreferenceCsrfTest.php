<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

// graph_realtime.php saved the real-time interval, window, size and thumbnail
// preferences on every request, and include/realtime.js sent them all by GET.
// csrf-magic checks the token only on POST, so another site could rewrite a
// user's saved preferences. Polling a graph the user may view stays a GET.

require_once dirname(__DIR__, 3) . '/Helpers/GraphRealtimeHarness.php';

$prefRequest = array('local_graph_id' => 5, 'ds_step' => 30, 'graph_start' => -300, 'size' => 50, 'graph_nolegend' => 'true', 'top' => 0, 'left' => 0);
$prefConfig  = array('realtime_enabled' => 'on', 'realtime_interval' => '10');
$savedPrefs  = array('realtime_interval' => 30, 'realtime_gwindow' => 300, 'realtime_size' => 50, 'realtime_nolegend' => 'true');

test('a GET polls the graph without saving preferences', function () use ($prefRequest, $prefConfig) {
    foreach (array('init', 'timespan', 'interval', 'countdown') as $action) {
        $run = graph_realtime_run(array('method' => 'GET', 'request' => array('action' => $action) + $prefRequest, 'allowed' => array(5), 'config' => $prefConfig));

        expect($run['polls'])->toBe(array('[-q][/opt/kadupul/poller_realtime.php][--graph=5][--interval=30][--poller_id=abc123]'))
            ->and($run['calls']['settings'])->toBe(array())
            ->and($run['response']['ds_step'])->toBe('30');
    }
});

test('a POST, which csrf-magic has checked, saves the preferences', function () use ($prefRequest, $prefConfig, $savedPrefs) {
    foreach (array('init', 'timespan', 'interval', 'countdown') as $action) {
        $run = graph_realtime_run(array('method' => 'POST', 'request' => array('action' => $action) + $prefRequest, 'allowed' => array(5), 'config' => $prefConfig));

        expect($run['polls'])->toHaveCount(1)
            ->and($run['calls']['settings'])->toBe($savedPrefs);
    }
});

test('opening the pop-out page by GET saves no preferences', function () use ($prefRequest, $prefConfig, $savedPrefs) {
    /* with real-time disabled the page stops right after the preference block */
    $config = array('realtime_enabled' => '') + $prefConfig;

    $run = graph_realtime_run(array('method' => 'GET', 'request' => $prefRequest, 'allowed' => array(5), 'config' => $config));

    expect($run['stdout'])->toContain('Real-time has been disabled')
        ->and($run['calls']['settings'])->toBe(array());

    $run = graph_realtime_run(array('method' => 'POST', 'request' => $prefRequest, 'allowed' => array(5), 'config' => $config));

    expect($run['calls']['settings'])->toBe($savedPrefs);
});
