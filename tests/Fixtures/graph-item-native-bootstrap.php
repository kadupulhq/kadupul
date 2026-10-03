<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$mode = getenv('GRAPH_ITEM_TEST_MODE');
$root = getenv('GRAPH_ITEM_TEST_ROOT');
require_once $root . '/lib/graph_item_editor.php';
$request = array('action' => str_starts_with($mode, 'save-') ? 'save' : str_replace('-single', '', $mode), 'id' => 8, 'graph_template_item_id' => 8,
    'graph_template_id' => 3, 'local_graph_id' => 4, 'data_template_id' => 2,
    'task_item_id' => 6, '_task_item_id' => 5, 'host_id' => 1,
    'graph_type_id' => 4, 'alpha' => 'FF', 'save_component_item' => 1,
    'sequence' => 1, 'color_id' => 0, 'consolidation_function_id' => 4);
if (str_starts_with($mode, 'save-')) {
    $request['graph_type_id'] = (int) substr($mode, 5);
}
if ($mode === 'save-20') {
    $request['line_width'] = '2.50';
}
if (getenv('GRAPH_ITEM_TEST_VALIDATION') === '1') {
    $request = array_replace($request, json_decode(getenv('GRAPH_ITEM_TEST_PAYLOAD'), true, 512, JSON_THROW_ON_ERROR));
}
$calls = array();
$graph_item_types = array(1 => 'COMMENT', 2 => 'HRULE', 3 => 'VRULE', 7 => 'AREA', 4 => 'LINE1', 5 => 'LINE2', 6 => 'LINE3', 9 => 'GPRINT', 10 => 'LEGEND', 15 => 'LEGEND_CAMM', 20 => 'LINE:STACK', 30 => 'TIC');
$struct_graph_item = array('task_item_id' => array('default' => 0), 'alpha' => array(), 'line_width' => graph_item_editor_line_width_field());
$consolidation_functions = array();
$config = array('url_path' => '/');
require_once $root . '/include/global_constants.php';
function get_request_var($name)
{
    return $GLOBALS['request'][$name] ?? '';
}
function get_nfilter_request_var($name)
{
    return get_request_var($name);
}
function get_filter_request_var($name)
{
    return (int) get_request_var($name);
}
function isset_request_var($name)
{
    return isset($GLOBALS['request'][$name]);
}
function isempty_request_var($name)
{
    return empty($GLOBALS['request'][$name]);
}
function set_request_var($name, $value)
{
    $GLOBALS['request'][$name] = $value;
}
function set_default_action() {}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function __($text, ...$values)
{
    return $values ? sprintf($text, ...$values) : (in_array($text, array('Cur:', 'Avg:', 'Min:', 'Max:'), true) ? 'translated:' . $text : $text);
}
function __esc($text, ...$values)
{
    return __($text, ...$values);
}
function html_escape($text)
{
    return htmlspecialchars($text, ENT_QUOTES);
}
function html_host_filter(...$args) {}
function top_header() {}
function bottom_footer() {}
function form_start(...$args) {}
function html_start_box(...$args) {}
function html_end_box(...$args) {}
function form_hidden_box(...$args) {}
function form_save_button(...$args) {}
function draw_edit_form($form)
{
    $GLOBALS['calls'][] = array('form', $form);
}
function validate_store_request_vars(...$args) {}
function load_current_session_value(...$args) {}
function kill_session_var(...$args) {}
function read_config_option($key)
{
    return $key === 'autocomplete_enabled' ? 1 : '';
}
function get_selected_theme()
{
    return 'modern';
}
function get_rrdtool_version()
{
    return '1.9';
}
class CactiSecureHeaders
{
    public static function getNonceAttribute()
    {
        return '';
    }
}
function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}
function db_fetch_assoc($sql)
{
    return array(array('id' => 2, 'name' => 'Data template'));
}
function db_fetch_assoc_prepared($sql, $params)
{
    if (str_contains($sql, 'SELECT DISTINCT dtr.data_template_id')) {
        return array(array('data_template_id' => 2));
    }
    if (str_contains($sql, 'gtin.id')) {
        return array(array('id' => 11, 'name' => 'Existing', 'task_item_id' => 6));
    }
    if (str_contains($sql, 'SELECT id')) {
        return array(array('id' => 8));
    }
    if (str_contains($sql, 'CONCAT_WS')) {
        return array(array('id' => 5, 'name' => 'First'), array('id' => 6, 'name' => 'Second'), array('id' => 7, 'name' => 'Third'));
    }
    return array();
}
function db_fetch_cell_prepared($sql, $params)
{
    if (str_contains($sql, 'graph_type_id')) {
        return str_ends_with($GLOBALS['mode'], '-single') ? 9 : 4;
    }
    if (str_contains($sql, 'task_item_id')) {
        return 5;
    }
    return 'Example';
}
function db_fetch_row_prepared($sql, $params)
{
    if (str_contains($sql, 'SELECT task_item_id')) {
        return array('task_item_id' => 6);
    }
    return array('id' => 8, 'graph_template_id' => 3, 'task_item_id' => 6, 'alpha' => 'FF', 'line_width' => '1', 'graph_type_id' => 4, 'local_graph_template_item_id' => 0);
}
function db_execute_prepared($sql, $params)
{
    $GLOBALS['calls'][] = array('execute', preg_replace('/\s+/', ' ', $sql), $params);
}
function db_fetch_insert_id()
{
    return 12;
}
function get_hash_graph_template(...$args)
{
    return 'fixture-hash';
}
if (getenv('GRAPH_ITEM_TEST_VALIDATION') === '1') {
    // Exercise the shipped validation procedure together with the actual editor.
    // Function extraction is behavioral evidence; it does not add source coverage.
    require_once $root . '/tests/Helpers/PhpSource.php';
    eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'form_input_validate'));
} else {
    function form_input_validate($value, ...$args)
    {
        return $value;
    }
}
function is_error_message()
{
    return !empty($_SESSION['sess_error_fields']);
}
function get_sequence(...$args)
{
    return 1;
}
function sql_save($row, $table)
{
    $GLOBALS['calls'][] = array('save', $row, $table);
    return 8;
}
function raise_message(...$args)
{
    if (getenv('GRAPH_ITEM_TEST_VALIDATION') === '1') {
        $GLOBALS['calls'][] = array('message', $args);
    }
}
function push_out_graph_item(...$args)
{
    $GLOBALS['calls'][] = array('push-item', $args);
}
function push_out_graph_input(...$args)
{
    $GLOBALS['calls'][] = array('push-input', $args);
}
function get_graph_group($id)
{
    return array($id => array());
}
function get_graph_parent($id, $direction)
{
    return str_ends_with($GLOBALS['mode'], '-single') ? 0 : 9;
}
function move_graph_group(...$args)
{
    $GLOBALS['calls'][] = array('move', $args);
}
function resequence_graphs_simple(...$args) {}
register_shutdown_function(function () {
    if (getenv('GRAPH_ITEM_TEST_VALIDATION') === '1') {
        $GLOBALS['calls'][] = array('validation', $_SESSION['sess_error_fields'] ?? array());
    }
    print "\nRESULT:" . json_encode($GLOBALS['calls'], JSON_THROW_ON_ERROR);
});

function get_item(...$args)
{
    return 9;
}
function move_item_down(...$args)
{
    $GLOBALS['calls'][] = array('move-single', 'next', $args);
}
function move_item_up(...$args)
{
    $GLOBALS['calls'][] = array('move-single', 'previous', $args);
}
