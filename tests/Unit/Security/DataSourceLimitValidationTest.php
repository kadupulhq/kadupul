<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace DataSourceLimitValidationTest;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

$root = dirname(__DIR__, 3);
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'form_input_validate'));
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'is_error_message'));
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents($root . '/data_sources.php'), 'form_save'));

function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function read_config_option($name)
{
    return '';
}
function raise_message($id, ...$args)
{
    $GLOBALS['limit_messages'][] = $id;
}
function cacti_log(...$args) {}
function isset_request_var($name)
{
    return isset($_REQUEST[$name]);
}
function isempty_request_var($name)
{
    return empty($_REQUEST[$name]);
}
function get_request_var($name)
{
    return $_REQUEST[$name] ?? '';
}
function get_filter_request_var($name, ...$args)
{
    return get_request_var($name);
}
function get_nfilter_request_var($name, $default = '')
{
    return $_REQUEST[$name] ?? $default;
}
function sql_save($save, $table)
{
    $GLOBALS['limit_saved'][$table] = $save;

    return 5;
}
function db_fetch_cell_prepared(...$args)
{
    return '0';
}
function set_config_option(...$args) {}
function update_data_source_title_cache(...$args) {}
function generate_data_source_path(...$args) {}
function update_poller_cache(...$args) {}
function header(...$args) {}

/** The validation pattern a page passes to form_input_validate() for $field. */
function limit_pattern(string $page, string $field): string
{
    $source = file_get_contents(dirname(__DIR__, 3) . '/' . $page);
    $call = preg_quote("\$save3['$field']", '/') . '\s*=\s*form_input_validate\([^\n]*?, (\'\^(?:[^\'\\\\]|\\\\.)*\'),';
    if (preg_match('/' . $call . '/', $source, $match) !== 1) {
        throw new \RuntimeException("No validation found for $field in $page");
    }

    // A single-quoted literal escapes only a backslash and a quote.
    return strtr(substr($match[1], 1, -1), array('\\\\' => '\\', "\\'" => "'"));
}

/** Whether form_input_validate() accepts $value with the page's own pattern. */
function limit_accepted(string $page, string $field, string $value): bool
{
    $_SESSION = array();
    form_input_validate($value, $field, limit_pattern($page, $field), false, 3);

    return !is_error_message();
}

$numbers = array('0', '-5', '2.5', '.5', '5.', '1e3', '-2.5E-3', 'U');
$refused = array('5 x', '0;x', '1U', 'U 0', 'x U', "5\n", '-', '1e', '0x10', ' 0', '+5', 'u');

test('a data source minimum accepts only a number or U', function ($page, $value, $expected) {
    expect(limit_accepted($page, 'rrd_minimum', $value))->toBe($expected);
})->with(function () use ($numbers, $refused) {
    $cases = array();
    foreach (array('data_sources.php', 'data_templates.php') as $page) {
        foreach ($numbers as $value) {
            $cases[] = array($page, $value, true);
        }
        foreach (array_merge($refused, array('|query_ifSpeed|', '|query_ifHighSpeed|')) as $value) {
            $cases[] = array($page, $value, false);
        }
    }

    return $cases;
});

test('a data source maximum accepts only a number, U or an interface speed', function ($page, $value, $expected) {
    expect(limit_accepted($page, 'rrd_maximum', $value))->toBe($expected);
})->with(function () use ($numbers, $refused) {
    $cases = array();
    foreach ($numbers as $value) {
        $cases[] = array('data_sources.php', $value, true);
        $cases[] = array('data_templates.php', $value, true);
    }
    foreach ($refused as $value) {
        $cases[] = array('data_sources.php', $value, false);
        $cases[] = array('data_templates.php', $value, false);
    }
    foreach (array('|query_ifSpeed|', '|query_ifHighSpeed|') as $token) {
        $cases[] = array('data_sources.php', $token, true);
        $cases[] = array('data_sources.php', $token . ' x', false);
        $cases[] = array('data_sources.php', 'x ' . $token, false);
    }
    $cases[] = array('data_templates.php', '|query_ifSpeed|', true);
    $cases[] = array('data_templates.php', '|query_ifSpeed| x', false);
    $cases[] = array('data_templates.php', 'x |query_ifSpeed|', false);
    // Templates never offered the high speed token.
    $cases[] = array('data_templates.php', '|query_ifHighSpeed|', false);

    return $cases;
});

test('a data source item that fails validation is not stored', function ($minimum, $stored) {
    $_SESSION = array();
    $GLOBALS['limit_saved'] = array();
    $GLOBALS['limit_messages'] = array();
    $_REQUEST = array(
        'save_component_data_source' => '1', 'local_data_id' => '5', 'data_template_id' => '0', '_data_template_id' => '0',
        'host_id' => '0', '_host_id' => '0', 'current_rrd' => '7', 'data_template_data_id' => '3',
        'local_data_template_data_id' => '0', 'data_input_id' => '1', '_data_input_id' => '1', 'name' => 'Traffic',
        'data_source_path' => 'rra/traffic_5.rrd', 'data_source_profile_id' => '1', 'rrd_step' => '300',
        'rrd_maximum' => 'U', 'rrd_minimum' => $minimum, 'rrd_heartbeat' => '600', 'data_source_type_id' => '1',
        'data_source_name' => 'value',
    );

    form_save();

    expect(isset($GLOBALS['limit_saved']['data_template_rrd']))->toBe($stored);
    if ($stored) {
        expect($GLOBALS['limit_saved']['data_template_rrd']['rrd_minimum'])->toBe($minimum);
    } else {
        expect($_SESSION['sess_error_fields'])->toBe(array('rrd_minimum' => 'rrd_minimum'));
    }
})->with(array(array('0', true), array('U', true), array('5 x', false), array('0;x', false)));
