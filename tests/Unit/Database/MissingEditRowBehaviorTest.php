<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function get_filter_request_var(...$args) { return 1; }
function get_request_var($name) { return $GLOBALS['missing_request'][$name] ?? 1; }
function isempty_request_var($name) { return empty($GLOBALS['missing_request'][$name]); }
function get_nonsystem_data_input($id) { return $id; }
function db_fetch_row_prepared(...$args) { return array_shift($GLOBALS['missing_rows']) ?? false; }
function isset_request_var($name) { return $name === 'selected_items' || !empty($GLOBALS['missing_request'][$name]); }
function get_nfilter_request_var($name, $default = '') { return $name === 'drp_action' ? ($GLOBALS['missing_request'][$name] ?? '2') : ($name === 'new_username' ? 'copied' : 1); }
function cacti_count($value) { return is_array($value) ? count($value) : 0; }
function sanitize_unserialize_selected_items($value) { return array(1); }
function user_copy(...$args) { throw new RuntimeException('Must not copy a missing user'); }
function raise_message($message) { $GLOBALS['missing_row_messages'][] = $message; }
function db_execute_prepared(...$args) { throw new RuntimeException('Must not write when the selected row is missing'); }
function form_start(...$args) { throw new RuntimeException('Must not render when the selected row is missing'); }
function html_start_box(...$args) { throw new RuntimeException('Must not render when the selected row is missing'); }

$source = file_get_contents(dirname(__DIR__, 3) . '/data_input.php');
foreach (array('data_input_save_message', 'field_remove_confirm', 'field_remove', 'field_edit', 'data_edit') as $name) {
    eval(test_php_function_source($source, $name));
}
eval(test_php_function_source(file_get_contents(dirname(__DIR__, 3) . '/color_templates_items.php'), 'aggregate_color_item_edit'));

eval(test_php_function_source(file_get_contents(dirname(__DIR__, 3) . '/user_admin.php'), 'form_actions'));
beforeEach(function () {
    $GLOBALS['missing_rows'] = array();
    $GLOBALS['missing_request'] = array('id' => 1, 'type' => 'in');
    $GLOBALS['missing_row_messages'] = array();
});

test('missing edit rows report failure before rendering or mutation', function ($function) {
    $GLOBALS['missing_row_messages'] = array();
    $function === 'data_input_save_message' ? $function(1) : $function();
    expect($GLOBALS['missing_row_messages'])->toBe(array(2));
})->with(array('data_input_save_message', 'field_remove_confirm', 'field_remove', 'field_edit', 'data_edit', 'aggregate_color_item_edit', 'form_actions'));

test('field editing refuses a missing parent after loading the requested field', function () {
    $GLOBALS['missing_rows'] = array(array('input_output' => 'in', 'data_input_id' => 1), false);
    field_edit();
    expect($GLOBALS['missing_row_messages'])->toBe(array(2));
});

test('new field editing requires an explicit direction and an existing parent', function ($type) {
    $GLOBALS['missing_request'] = array('id' => 0, 'type' => $type);
    field_edit();
    expect($GLOBALS['missing_row_messages'])->toBe(array(2));
})->with(array('', 'in'));

test('existing data input counts retain their success and dependency messages', function ($templates, $sources, $type, $message) {
    $GLOBALS['missing_rows'] = array(array('templates' => $templates, 'data_sources' => $sources));
    data_input_save_message(1, $type);
    expect($GLOBALS['missing_row_messages'])->toBe(array($message));
})->with(array(array(0, 0, 'input', 1), array(1, 0, 'input', 'input_save_wo_ds'),
    array(1, 0, 'output', 'input_field_save_wo_ds'), array(1, 1, 'input', 'input_save_w_ds'),
    array(1, 1, 'output', 'input_field_save_w_ds')));


test('missing data-input aggregates return NULL fields instead of a missing row', function () {
    $GLOBALS['missing_rows'] = array(array('templates' => null, 'data_sources' => null));
    data_input_save_message(999);
    expect($GLOBALS['missing_row_messages'])->toBe(array(2));
});


test('existing color parent with a missing requested item stops before rendering', function () {
    $GLOBALS['missing_rows'] = array(array('name' => 'Palette'), false);
    $GLOBALS['missing_request']['color_template_item_id'] = 99;
    aggregate_color_item_edit();
    expect($GLOBALS['missing_row_messages'])->toBe(array(2));
});

test('batch user copy refuses missing source and destination users', function ($template, $user) {
    $GLOBALS['missing_request']['drp_action'] = '5';
    $GLOBALS['missing_rows'] = array($template, $user);
    form_actions();
    expect($GLOBALS['missing_row_messages'])->toBe(array(2));
})->with(array(array(false, array('username' => 'target', 'realm' => 0)), array(array('username' => 'source', 'realm' => 0), false), array(false, false)));
