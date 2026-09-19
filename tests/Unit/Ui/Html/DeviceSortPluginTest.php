<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
namespace DeviceSortPluginTest;
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 4) . '/lib/functions.php';
require_once dirname(__DIR__, 4) . '/lib/html_utility.php';
$source = file_get_contents(dirname(__DIR__, 4) . '/host.php');
foreach (array('get_device_display_columns', 'get_device_records') as $name) {
    eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($source, $name));
}
function __($value) { return $value; }
function read_config_option($key) { return 300; }
function get_request_var($key) { return \get_request_var($key); }
function get_order_string($columns) { return \get_order_string($columns); }
function get_total_row_data(...$args) { return 1; }
function db_fetch_assoc($sql) { $GLOBALS['device_sort_sql'] = $sql; return array(); }
function api_plugin_hook_function($hook, $value) {
    if ($hook === 'device_display_text') {
        $GLOBALS['device_sort_hook_calls']++;
        $value['plugin_rank'] = array('display' => 'Rank', 'sort' => 'ASC');
        $value['nosort_action'] = array('display' => 'Action');
        foreach (array('host.plugin_rank', 'LENGTH(description)', 'description`', 'description, (SELECT 1)') as $column) {
            $value[$column] = array('display' => 'Plugin column', 'sort' => 'ASC');
        }
    }
    return $value;
}

test('device display and export preserve plugin sorting with a fresh session', function ($column, $provided) {
    $GLOBALS['config'] = array('is_web' => false, 'config_options_array' => array('allow_unsafe_metachars' => ''));
    $_SESSION = array('sess_user_id' => 1); $_REQUEST = $_GET = $_POST = array();
    $GLOBALS['_CACTI_REQUEST'] = array(); $_SERVER['SCRIPT_NAME'] = 'host.php';
    $GLOBALS['device_sort_hook_calls'] = 0;
    foreach (array('filter'=>'', 'location'=>'-1', 'host_status'=>'-1', 'host_template_id'=>'-1', 'site_id'=>'-1', 'poller_id'=>'-1', 'page'=>1, 'sort_column'=>$column, 'sort_direction'=>'DESC') as $key=>$value) {
        \set_request_var($key, $value);
    }
    $columns = $provided ? api_plugin_hook_function('device_display_text', get_device_display_columns()) : null;
    $total = 0;
    get_device_records($total, 30, $columns);
    expect($GLOBALS['device_sort_hook_calls'])->toBe(1);
    if (in_array($column, array('description', 'plugin_rank', 'host.plugin_rank'), true)) {
        expect($GLOBALS['device_sort_sql'])->toContain('ORDER BY `' . str_replace('.', '`.`', $column) . '` DESC');
    } else {
        expect($GLOBALS['device_sort_sql'])->toContain('ORDER BY `description` ASC')->not->toContain('ORDER BY ' . $column);
    }
    unset($GLOBALS['device_sort_sql'], $GLOBALS['device_sort_hook_calls']);
})->with(array('description', 'plugin_rank', 'host.plugin_rank', 'nosort_action', 'unknown_column', 'LENGTH(description)', 'description`', 'description, (SELECT 1)'))->with(array(false, true));

test('links keep sortorder ascending while preserving other requested directions', function ($column, $expected) {
    $GLOBALS['config'] = array('is_web' => false, 'config_options_array' => array('allow_unsafe_metachars' => ''));
    $_SESSION = array('sess_user_id' => 1); $_REQUEST = $_GET = $_POST = array();
    $GLOBALS['_CACTI_REQUEST'] = array(); $_SERVER['SCRIPT_NAME'] = 'links.php';
    \set_request_var('sort_column', $column);
    \set_request_var('sort_direction', 'DESC');
    $source = file_get_contents(dirname(__DIR__, 4) . '/links.php');
    $start = strpos($source, '$sql_order = get_order_string(');
    $end = strpos($source, '$sql_limit =', $start);
    eval('namespace ' . __NAMESPACE__ . '; ' . substr($source, $start, $end - $start));
    expect($sql_order)->toContain($expected);
})->with(array(array('sortorder', '`sortorder` ASC'), array('title', '`title` DESC')));
