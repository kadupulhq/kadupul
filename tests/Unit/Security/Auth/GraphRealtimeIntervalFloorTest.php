<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

// Settings > Graphing > Minimum Refresh Interval is the shortest real-time step
// the administrator allows. The pop-out list hid shorter steps, but the server
// took any ds_step of 1 or more, passed it to poller_realtime.php as the RRD
// step and saved it as the user's interval, and the inline lists offered every
// step.

require_once dirname(__DIR__, 3) . '/Helpers/GraphRealtimeHarness.php';

$floorRequest = array('action' => 'init', 'local_graph_id' => 5, 'graph_start' => -60, 'size' => 100, 'top' => 0, 'left' => 0);
$floorConfig  = array('realtime_enabled' => 'on', 'realtime_interval' => '10');

test('a step below the minimum refresh interval is raised to it before polling and saving', function ($step) use ($floorRequest, $floorConfig) {
    foreach (array('init', 'interval', 'countdown') as $action) {
        $run = graph_realtime_run(array('method' => 'POST', 'request' => array('action' => $action, 'ds_step' => $step) + $floorRequest, 'allowed' => array(5), 'config' => $floorConfig));

        expect($run['polls'])->toBe(array('[-q][/opt/kadupul/poller_realtime.php][--graph=5][--interval=10][--poller_id=abc123]'))
            ->and($run['calls']['settings']['realtime_interval'])->toBe(10)
            ->and($run['calls']['session']['sess_realtime_ds_step'])->toBe(10)
            ->and($run['calls']['session']['sess_realtime_dsstep'])->toBe(10)
            ->and($run['response']['ds_step'])->toBe('10');
    }
})->with(array('1' => 1, '5' => 5));

test('a stored interval below the floor is raised when the request carries none', function () use ($floorRequest, $floorConfig) {
    $run = graph_realtime_run(array('method' => 'POST', 'request' => $floorRequest, 'user' => array('realtime_interval' => '2'), 'allowed' => array(5), 'config' => $floorConfig));

    expect($run['polls'])->toBe(array('[-q][/opt/kadupul/poller_realtime.php][--graph=5][--interval=10][--poller_id=abc123]'))
        ->and($run['calls']['settings']['realtime_interval'])->toBe(10);
});

test('a step at or above the floor is kept', function () use ($floorRequest, $floorConfig) {
    $run = graph_realtime_run(array('method' => 'POST', 'request' => array('ds_step' => 30) + $floorRequest, 'allowed' => array(5), 'config' => $floorConfig));

    expect($run['polls'])->toBe(array('[-q][/opt/kadupul/poller_realtime.php][--graph=5][--interval=30][--poller_id=abc123]'))
        ->and($run['calls']['settings']['realtime_interval'])->toBe(30)
        ->and($run['response']['ds_step'])->toBe('30');
});

test('a refused request reports the floor, not a shorter requested step', function () use ($floorRequest, $floorConfig) {
    $run = graph_realtime_run(array('method' => 'POST', 'request' => array('ds_step' => 1) + $floorRequest, 'allowed' => array(6), 'config' => $floorConfig));

    expect($run['polls'])->toBe(array())
        ->and($run['response']['ds_step'])->toBe('10');
});

test('the pop-out page saves the floor for a shorter requested step', function () use ($floorConfig) {
    /* with real-time disabled the page stops right after saving preferences */
    $run = graph_realtime_run(array('method' => 'POST', 'request' => array('local_graph_id' => 5, 'ds_step' => 1, 'graph_start' => -60, 'size' => 100), 'allowed' => array(5), 'config' => array('realtime_enabled' => '') + $floorConfig));

    expect($run['stdout'])->toContain('Real-time has been disabled')
        ->and($run['calls']['settings']['realtime_interval'])->toBe(10);
});

test('the inline real-time interval lists offer only steps at or above the floor', function ($file) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

    expect(preg_match("/<select[^>]*id='ds_step'>\\s*<\\?php(?P<code>.*?)\\?>\\s*<\\/select>/s", $source, $match))->toBe(1);

    $program = 'function read_config_option($name) { return $name === "realtime_interval" ? "10" : ""; }'
        . '$realtime_refresh = array(1 => "1s", 5 => "5s", 10 => "10s", 30 => "30s");'
        . '$_SESSION = array("sess_realtime_dsstep" => 10);'
        . $match['code'];

    $process = proc_open(array(PHP_BINARY, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $stdout  = stream_get_contents($pipes[1]);
    $stderr  = stream_get_contents($pipes[2]);
    proc_close($process);

    expect($stderr)->toBe('')
        ->and($stdout)->toBe('<option value="10" selected="selected">10s</option><option value="30">30s</option>');
})->with(array('lib/html_graph.php', 'lib/html_tree.php'));
