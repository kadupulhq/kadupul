<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
class ZoomRedirect extends RuntimeException {}
class ZoomRenderReached extends RuntimeException {}
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function __($message) { return $message; }
function raise_message(...$args) { $GLOBALS['zoom_messages'][] = $args; }
function cacti_header($location) { throw new ZoomRedirect($location); }
function get_request_var($name) { return $GLOBALS['zoom_request'][$name] ?? ''; }
function isset_request_var($name) { return isset($GLOBALS['zoom_request'][$name]); }
function db_fetch_cell_prepared(...$args) { return 60; }
function db_fetch_row_prepared($sql, $params) {
    return strpos($sql, 'data_source_profiles_rra') !== false ? $GLOBALS['zoom_rra'] : $GLOBALS['zoom_graph'];
}
function read_user_setting(...$args) { throw new ZoomRenderReached('valid graph reached rendering'); }
if (!defined('MESSAGE_LEVEL_ERROR')) { define('MESSAGE_LEVEL_ERROR', 3); }

function execute_zoom_branch(array $rras) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/graph.php');
    $start = strpos($source, "case 'zoom':");
    $end = strpos($source, "case 'properties':", $start);
    if ($start === false || $end === false) { throw new RuntimeException('Graph zoom branch not found'); }
    eval("switch ('zoom') {" . substr($source, $start, $end - $start) . '}');
}

test('zoom refuses missing RRA and graph rows before using their fields', function ($missing, $requested) {
    $GLOBALS['zoom_request'] = array('local_graph_id' => 1, 'rra_id' => $requested);
    $GLOBALS['zoom_messages'] = array();
    $rra = array('id' => 1, 'steps' => 1, 'rows' => 10, 'rrd_step' => 60, 'step' => 60);
    $GLOBALS['zoom_rra'] = $missing === 'rra' ? false : $rra;
    $GLOBALS['zoom_graph'] = false;
    expect(fn () => execute_zoom_branch($missing === 'all' ? array() : array($rra)))
        ->toThrow(ZoomRedirect::class, 'graph_view.php');
    expect($GLOBALS['zoom_messages'])->toHaveCount(1)
        ->and($GLOBALS['zoom_messages'][0][0])->toBe('graph_not_found');
})->with(array(array('all', 0), array('rra', 0), array('rra', 1), array('graph', 0), array('graph', 1)));

test('valid zoom metadata continues into rendering', function () {
    $GLOBALS['zoom_request'] = array('local_graph_id' => 1, 'rra_id' => 1);
    $GLOBALS['zoom_messages'] = array();
    $rra = array('id' => 1, 'steps' => 1, 'rows' => 10, 'rrd_step' => 60, 'step' => 60);
    $GLOBALS['zoom_rra'] = $rra;
    $GLOBALS['zoom_graph'] = array('height' => 100, 'width' => 300, 'graph_template_id' => 1);
    expect(fn () => execute_zoom_branch(array($rra)))->toThrow(ZoomRenderReached::class);
    expect($GLOBALS['zoom_messages'])->toBe(array());
});
