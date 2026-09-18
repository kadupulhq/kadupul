<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

/*
 * Runs graph_image.php and graph_json.php as a remote collector without
 * local storage, with auth.php and the renderer stubbed, and records whether
 * the page proxied the request to the main poller's remote_agent.php.
 */

$root = dirname(__DIR__, 4);

$runGraphPage = function ($page, $allowed) use ($root) {
    $program = <<<'PHP'
$input   = json_decode($argv[1], true);
$calls   = array();
$config  = array('poller_id' => 2, 'url_path' => '/cacti/');
$_SESSION = array('sess_user_id' => 5);
$request = array('local_graph_id' => '7', 'image_format' => 'png');

define('FILTER_VALIDATE_MAX_DATE_AS_INT', 2088385563);

register_shutdown_function(function () {
    $body = '';
    while (ob_get_level()) {
        $body = ob_get_clean() . $body;
    }
    echo json_encode(array('body' => $body, 'calls' => $GLOBALS['calls']));
});

function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array()) { return get_request_var($name); }
function get_request_var($name) { return isset($GLOBALS['request'][$name]) ? $GLOBALS['request'][$name] : ''; }
function get_nfilter_request_var($name) { return get_request_var($name); }
function set_request_var($name, $value) { $GLOBALS['request'][$name] = $value; }
function isset_request_var($name) { return isset($GLOBALS['request'][$name]); }
function isempty_request_var($name) { return empty($GLOBALS['request'][$name]); }
function api_plugin_hook_function($name, $value = '') { return $value; }
function db_fetch_cell_prepared($sql, $params = array()) { return '1'; }
function cacti_session_close() {}
function cacti_validate_theme($theme) { return $theme; }
function get_selected_theme() { return 'modern'; }
function read_config_option($name) { return $name == 'stats_poller' ? 'on' : ''; }
function __($text) { return $text; }
function is_graph_allowed($local_graph_id, $user = 0) {
    $GLOBALS['calls'][] = 'allowed:' . $local_graph_id . ':' . $user;
    return $GLOBALS['input']['allowed'];
}
function call_remote_data_collector($poller_id, $url) {
    $GLOBALS['calls'][] = 'remote:' . $url;
    return "image = x\nREMOTE";
}
function rrdtool_function_graph($local_graph_id, $rra_id, $graph_data_array, $pipe = false, &$meta = array(), $user = 0) {
    $GLOBALS['calls'][] = 'render:' . (isset($graph_data_array['get_error']) ? 'error' : 'graph');
    return false;
}
function rrdtool_create_error_image($error, $width = 0, $height = 0) { return 'ERRORIMAGE'; }

ob_start();
ob_start();

$source = file_get_contents(getcwd() . '/' . $input['page']);
$source = str_replace(array("include('./include/auth.php');", "include_once('./lib/rrd.php');"), '', $source);

// test-only eval of a page read from this repository, not external input
eval('?>' . $source);
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program, json_encode(array('page' => $page, 'allowed' => $allowed))),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $root
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $stderr . $stdout);

    return json_decode($stdout, true);
};

test('graph_image.php answers a denied graph without calling the main poller', function () use ($runGraphPage) {
    $result = $runGraphPage('graph_image.php', false);

    expect($result['body'])->toBe('GRAPH ACCESS DENIED')
        ->and($result['calls'])->toBe(array('allowed:7:5'));
});

test('graph_json.php answers a denied graph without calling the main poller', function () use ($runGraphPage) {
    $result = $runGraphPage('graph_json.php', false);
    $json   = json_decode($result['body'], true);

    expect($json['type'])->toBe('png')
        ->and($json['image'])->toBe(base64_encode('ERRORIMAGE'))
        ->and($result['calls'])->toBe(array('allowed:7:5', 'render:error'));
});

test('both pages still proxy an allowed graph with the session user', function () use ($runGraphPage) {
    foreach (array('graph_image.php', 'graph_json.php') as $page) {
        $result = $runGraphPage($page, true);

        expect($result['calls'][0])->toBe('allowed:7:5')
            ->and($result['calls'][1])->toStartWith('remote:/cacti/remote_agent.php?action=graph_json&local_graph_id=7')
            ->and($result['calls'][1])->toContain('&effective_user=5');
    }
});
