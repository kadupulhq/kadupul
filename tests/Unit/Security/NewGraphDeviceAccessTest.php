<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('graph creation is refused for devices the user cannot access', function ($host_id, $expected, $form_type = 'cg') {
    $root = dirname(__DIR__, 3);
    $program = 'require ' . var_export($root . '/tests/Helpers/PhpSource.php', true) . ';eval(test_php_function_source(file_get_contents(' . var_export($root . '/lib/auth.php', true) . '), ' . var_export('auth_resource_id', true) . '));require ' . var_export($root . '/lib/template.php', true) . ';$host_id=' . var_export($host_id, true) . ';$form_type=' . var_export($form_type, true) . ';';
    $program .= <<<'PROBE'
function is_device_allowed($id) { return $id === 12; }
function cacti_log($message, ...$args) { echo 'LOG:' . $message; }
// Past the guard, stop before graph creation touches the database.
function input_validate_input_number($value) { echo 'CREATE'; exit; }
var_export(create_save_graph($host_id, $form_type, 5, array(), array()));
PROBE;
    $process = proc_open(array(PHP_BINARY, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)
        ->and($error)->toBe('')
        ->and($output)->toBe($expected);
})->with(array(
    'allowed device' => array(12, 'CREATE'),
    'foreign device' => array(13, 'LOG:WARNING: Graph creation rejected for a device the current user cannot access.false'),
    'no device' => array(0, 'CREATE'),
    'no device query' => array(0, 'LOG:WARNING: Graph creation rejected for a device the current user cannot access.false', 'sg'),
    'negative device' => array(-1, 'LOG:WARNING: Graph creation rejected for a device the current user cannot access.false'),
    'missing device' => array(null, 'LOG:WARNING: Graph creation rejected for a device the current user cannot access.false'),
    'fractional device' => array('12.5', 'LOG:WARNING: Graph creation rejected for a device the current user cannot access.false'),
    'array device' => array(array(12), 'LOG:WARNING: Graph creation rejected for a device the current user cannot access.false'),
    'equivalent device' => array('012 ', 'CREATE'),
));

test('the production new graph save carries a non-device template to the creation adapter', function () {
    $root = dirname(__DIR__, 3);
    $program = 'require ' . var_export($root . '/tests/Helpers/PhpSource.php', true) . ';$root=' . var_export($root, true) . ';';
    $program .= <<<'PROBE'
foreach (array('lib/auth.php' => array('auth_resource_id'), 'graphs_new.php' => array('graphs_new_host_is_allowed', 'host_new_graphs_save'), 'lib/template.php' => array('create_save_graph')) as $file => $functions) {
    foreach ($functions as $function) { eval(test_php_function_source(file_get_contents($root . '/' . $file), $function)); }
}
function is_device_allowed($id) { throw new RuntimeException('None must not depend on another device permission'); }
function get_nfilter_request_var($name) { return serialize(array('cg' => array(5 => array()))); }
function cacti_unserialize($value) { return unserialize($value, array('allowed_classes' => false)); }
function is_error_message() { return false; }
function input_validate_input_number($value) { if ($value !== 5) { throw new RuntimeException('Unexpected template'); } }
function debug_log_clear($name) {}
function test_data_sources($template, $host, $query, $index, $params) { return true; }
function create_complete_graph_from_template($template, $host, $query, &$params) {
    echo json_encode(array('template' => $template, 'host' => $host, 'query' => $query, 'params' => $params));
    return false;
}
function debug_log_insert(...$args) {}
function __esc($value, ...$args) { return vsprintf($value, $args); }
function cacti_log(...$args) { throw new RuntimeException('Unexpected denial'); }
$_POST = array('g_0_5_title' => 'Non-device graph');
host_new_graphs_save(0);
PROBE;
    $process = proc_open(array(PHP_BINARY, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    expect(is_resource($process))->toBeTrue();
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('');
    expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe(array(
        'template' => 5, 'host' => 0, 'query' => array(),
        'params' => array(5 => array('graph_template' => array('title' => 'Non-device graph'))),
    ));
});

test('the production query reload still requires an authorized real device', function ($host, $expected) {
    $root = dirname(__DIR__, 3);
    $program = 'require ' . var_export($root . '/tests/Helpers/PhpSource.php', true) . ';$root=' . var_export($root, true) . ';$host=' . var_export($host, true) . ';';
    $program .= <<<'PROBE'
foreach (array('lib/auth.php' => array('auth_resource_id'), 'graphs_new.php' => array('graphs_new_host_is_allowed', 'host_reload_query')) as $file => $functions) {
    foreach ($functions as $function) { eval(test_php_function_source(file_get_contents($root . '/' . $file), $function)); }
}
function is_device_allowed($id) { return $id === 12; }
function get_filter_request_var($name) { return get_request_var($name); }
function get_request_var($name) { return $name === 'host_id' ? $GLOBALS['host'] : 7; }
function raise_message(...$args) { echo 'DENIED'; }
function __($value) { return $value; }
define('MESSAGE_LEVEL_ERROR', 1);
function run_data_query($host, $query) { echo 'QUERY:' . $host . ':' . $query; }
host_reload_query();
PROBE;
    $process = proc_open(array(PHP_BINARY, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    expect(is_resource($process))->toBeTrue();
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $error)->and($error)->toBe('')->and($output)->toBe($expected);
})->with(array(
    'None' => array(0, 'DENIED'), 'foreign' => array(13, 'DENIED'),
    'allowed' => array(12, 'QUERY:12:7'), 'negative' => array(-1, 'DENIED'),
));
