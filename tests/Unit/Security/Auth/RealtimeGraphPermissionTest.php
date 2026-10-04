<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * graph_realtime.php is a guest page, so include/auth.php returns before it
 * checks the Real-time realm (25). The init, timespan, interval and countdown
 * actions start poller_realtime.php, which polls every device behind the
 * graph, and the graph permission was checked only afterwards by the
 * renderer. The page now refuses before polling when real-time is disabled,
 * the user lacks the realm or may not view the graph.
 *
 * graph_realtime.php runs in a child process with include/auth.php and
 * lib/rrd.php replaced by empty files and the helpers it calls stubbed.
 */

/**
 * @param array<string, mixed> $options
 *
 * @return array{status: int, polled: bool, rendered: bool, saved: array<string, mixed>, graph_checks: list<array{0: int, 1: int}>, output: string}
 */
function realtime_gate_run(array $options): array
{
    $options += array(
        'action'  => 'init',
        'graph'   => '7',
        'enabled' => 'on',
        'realm'   => true,
        'graph_allowed' => true,
        'method'  => 'GET',
        'request' => array(),
    );

    $root = dirname(__DIR__, 4);
    $dir  = sys_get_temp_dir() . '/realtime-gate-' . bin2hex(random_bytes(8));

    mkdir($dir . '/include', 0700, true);
    mkdir($dir . '/lib', 0700);
    mkdir($dir . '/cache', 0700);
    file_put_contents($dir . '/include/auth.php', '<?php');
    file_put_contents($dir . '/lib/rrd.php', '<?php');
    file_put_contents($dir . '/cache/user_abc123_lgi_7.png', 'CACHED');

    $program = <<<'PHP'
require $argv[1] . '/include/global_constants.php';
require $argv[1] . '/lib/html_utility.php';
$options = json_decode($argv[2], true);
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function die_html_input_error(...$args) { http_response_code(400); exit; }
function read_user_setting($name, $default = null) { return $default; }
function set_user_setting($name, $value) { $GLOBALS['trace']['saved'][$name] = $value; }
function read_config_option($name) {
    if ($name === 'realtime_enabled') return $GLOBALS['options']['enabled'];
    if ($name === 'path_php_binary') return '/usr/bin/php';
    return getcwd() . '/cache';
}
function db_fetch_row_prepared(...$args) { return array(); }
function db_fetch_cell_prepared(...$args) { return '1'; }
function get_guest_account() { return 0; }
function cacti_log(...$args) {}
function __($text) { return $text; }
function html_escape($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function is_realm_allowed($realm) { return $realm === 25 && $GLOBALS['options']['realm']; }
function is_graph_allowed($local_graph_id, $user) {
    $GLOBALS['trace']['graph_checks'][] = array((int) $local_graph_id, (int) $user);
    return $GLOBALS['options']['graph_allowed'];
}
function rrdtool_create_error_image($message) { return 'ERROR:' . $message; }
function cacti_exec($binary, $args, &$output, $timeout) { $GLOBALS['trace']['polled'] = true; return 0; }
function rrdtool_function_graph(...$args) { $GLOBALS['trace']['rendered'] = true; return 'IMAGE'; }
function get_selected_theme() { return 'modern'; }
$config = array('base_path' => '/application');
$trace = array('polled' => false, 'rendered' => false, 'saved' => array(), 'graph_checks' => array());
$_SESSION = array('sess_user_id' => 42, 'sess_realtime_hash' => 'abc123');
$_REQUEST = array('action' => $options['action'], 'local_graph_id' => $options['graph']) + $options['request'];
$_SERVER['REQUEST_METHOD'] = $options['method'];
register_shutdown_function(function () {
    $output = '';
    while (ob_get_level()) {
        $output = ob_get_clean() . $output;
    }
    echo json_encode($GLOBALS['trace'] + array('status' => http_response_code() ?: 200, 'output' => $output));
});
ob_start();
require $argv[1] . '/graph_realtime.php';
PHP;

    try {
        $process = proc_open(
            array(PHP_BINARY, '-r', $program, $root, json_encode($options)),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $dir
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start graph_realtime.php probe');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0 || $stderr !== '') {
            throw new RuntimeException($stderr . $stdout);
        }

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        unlink($dir . '/cache/user_abc123_lgi_7.png');
        unlink($dir . '/include/auth.php');
        unlink($dir . '/lib/rrd.php');
        rmdir($dir . '/cache');
        rmdir($dir . '/include');
        rmdir($dir . '/lib');
        rmdir($dir);
    }
}

dataset('polling actions', array('init', 'timespan', 'interval', 'countdown'));

dataset('refusals', array(
    'real-time disabled' => array(array('enabled' => ''), 'Real-time has been disabled by your administrator.', array()),
    'no Real-time realm' => array(array('realm' => false), 'Permission Denied', array()),
    'graph not allowed'  => array(array('graph_allowed' => false), 'Permission Denied', array(array(7, 42))),
));

test('a refused real-time request polls nothing and saves nothing', function ($action, $options, $message, $checks) {
    $run   = realtime_gate_run(array('action' => $action) + $options);
    if ($message === 'Permission Denied') {
        expect($run['polled'])->toBeFalse()->and($run['rendered'])->toBeFalse()
            ->and($run['saved'])->toBe(array())->and($run['graph_checks'])->toBe($checks)
            ->and($run['status'])->toBe(403)->and($run['output'])->toBe('');
        return;
    }
    $reply = json_decode($run['output'], true, 512, JSON_THROW_ON_ERROR);

    expect($run['polled'])->toBeFalse()
        ->and($run['rendered'])->toBeFalse()
        ->and($run['saved'])->toBe(array())
        ->and($run['graph_checks'])->toBe($checks)
        ->and($run['status'])->toBe(200)
        ->and(base64_decode($reply['data']))->toBe('ERROR:' . $message)
        ->and(array_keys($reply))->toBe(array('local_graph_id', 'top', 'left', 'ds_step', 'graph_start', 'size', 'thumbnails', 'data', 'image_format'))
        ->and($reply['ds_step'])->toBe('10')
        ->and($reply['graph_start'])->toBe('-60')
        ->and($reply['size'])->toBe('100');
})->with('polling actions')->with('refusals');

test('an allowed real-time request checks the graph and then polls', function ($action) {
    $run = realtime_gate_run(array('action' => $action));

    expect($run['graph_checks'])->toBe(array(array(7, 42)))
        ->and($run['polled'])->toBeTrue()
        ->and($run['rendered'])->toBeTrue()
        ->and($run['status'])->toBe(200);
})->with('polling actions');

test('a graph id that is not positive is still rejected before any permission lookup', function ($graph) {
    $run = realtime_gate_run(array('graph' => $graph));

    expect($run['status'])->toBe(400)
        ->and($run['graph_checks'])->toBe(array())
        ->and($run['polled'])->toBeFalse();
})->with(array('0', '-1', ''));

test('view refuses the cached image to a user who may not see the graph', function ($options) {
    $run = realtime_gate_run(array('action' => 'view') + $options);

    expect($run['status'])->toBe(403)
        ->and($run['output'])->toBe('');
})->with(array(
    'real-time disabled' => array(array('enabled' => '')),
    'no Real-time realm' => array(array('realm' => false)),
    'graph not allowed'  => array(array('graph_allowed' => false)),
));

test('view still returns the cached image to an allowed user', function () {
    $run = realtime_gate_run(array('action' => 'view'));

    expect($run['status'])->toBe(200)
        ->and($run['output'])->toBe(base64_encode('CACHED'));
});

test('the real-time page tells a user without the realm that permission is denied', function () {
    $run = realtime_gate_run(array('action' => '', 'realm' => false));

    expect($run['output'])->toContain('Permission Denied')
        ->and($run['polled'])->toBeFalse();
});

/*
 * The four real-time preferences were saved on every request, and
 * include/realtime.js sent them all by GET. csrf-magic checks the token only
 * on POST, so another site could rewrite a user's saved preferences. Polling
 * a graph stays a GET; only a POST, which has passed csrf-magic, saves.
 */
dataset('preference request', array(
    array(array('ds_step' => '30', 'graph_start' => '-300', 'size' => '50', 'graph_nolegend' => 'true')),
));

test('a real-time GET polls the graph without saving preferences', function ($action, $request) {
    $run = realtime_gate_run(array('action' => $action, 'method' => 'GET', 'request' => $request));

    expect($run['polled'])->toBeTrue()
        ->and($run['saved'])->toBe(array());
})->with('polling actions')->with('preference request');

test('a real-time POST saves the preferences', function ($action, $request) {
    $run = realtime_gate_run(array('action' => $action, 'method' => 'POST', 'request' => $request));

    expect($run['polled'])->toBeTrue()
        ->and($run['saved'])->toBe(array('realtime_interval' => 30, 'realtime_gwindow' => 300, 'realtime_size' => 50, 'realtime_nolegend' => 'true'));
})->with('polling actions')->with('preference request');

test('opening the real-time page saves preferences only from a POST', function ($request) {
    $get  = realtime_gate_run(array('action' => '', 'enabled' => '', 'method' => 'GET', 'request' => $request));
    $post = realtime_gate_run(array('action' => '', 'enabled' => '', 'method' => 'POST', 'request' => $request));

    expect($get['output'])->toContain('Real-time has been disabled')
        ->and($get['saved'])->toBe(array())
        ->and($post['saved'])->toBe(array('realtime_interval' => 30, 'realtime_gwindow' => 300, 'realtime_size' => 50, 'realtime_nolegend' => 'true'));
})->with('preference request');
