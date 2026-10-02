<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = dirname(__DIR__, 2);
$exponent = $argv[1];
$graphId = (int) $argv[2];
$directory = $argv[3];
$config = array('base_path' => $root, 'library_path' => $root . '/lib', 'include_path' => $root . '/include', 'rra_path' => $directory, 'cacti_server_os' => 'unix', 'poller_id' => 2, 'connection' => 'offline', 'url_path' => '/', 'is_web' => false, 'config_options_array' => array('default_date_format' => '4', 'default_datechar' => '1', 'storage_location' => 0, 'rrdtool_version' => '1.8.0', 'path_rrdtool' => '/opt/homebrew/bin/rrdtool', 'date' => '2026-09-30 00:00:00', 'graph_dateformat' => 'Y-m-d H:i:s', 'graph_watermark' => '', 'rrdtool_watermark' => '', 'font_method' => '1', 'selected_theme' => 'classic', 'auth_method' => 0, 'log_destination' => 0, 'log_verbosity' => 0));
$_SESSION = array();
define('CACTI_LOCALE', 'en-US');
function __($message, ...$args)
{
    return $args ? vsprintf($message, $args) : $message;
}
function __x($context, $message, ...$args)
{
    return __($message, ...$args);
}
function get_installed_locales()
{
    return array('en-US' => 'English');
}
function get_new_user_default_language()
{
    return 'en-US';
}
function __esc($message, ...$args)
{
    return htmlspecialchars(__($message, ...$args), ENT_QUOTES);
}
function __n($singular, $plural, $number)
{
    return $number == 1 ? $singular : $plural;
}
function number_format_i18n($value, $decimals = 0)
{
    return number_format($value, $decimals);
}
$db = new PDO('sqlite::memory:');
$graph = array_fill_keys(array('id', 'title', 't_title', 'graph_template_id', 'local_graph_template_graph_id', 'auto_scale', 'auto_scale_opts', 'upper_limit', 'lower_limit', 'auto_scale_log', 'scale_log_units', 'auto_scale_rigid', 'auto_padding', 'unit_value', 'unit_exponent_value', 'height', 'width', 'graph_nolegend', 'image_format_id', 'title_cache', 'alt_y_grid', 'base_value', 'vertical_label', 'slope_mode', 'right_axis', 'right_axis_label', 'right_axis_format', 'no_gridfit', 'unit_length', 'tab_width', 'dynamic_labels', 'force_rules_legend', 'legend_position', 'legend_direction', 'left_axis_formatter', 'right_axis_formatter'), '');
$graph = array_replace($graph, array('id' => '9', 'title' => 'Stored graph', 'graph_template_id' => '9', 'image_format_id' => '1', 'unit_exponent_value' => $exponent, 'height' => '100', 'width' => '300', 'base_value' => '1000', 'local_graph_id' => $graphId));
$db->exec('CREATE TABLE graph_templates_graph (' . implode(',', array_map(static fn($key) => $key . ($key === 'id' ? ' INTEGER PRIMARY KEY' : ' TEXT'), array_keys($graph))) . ')');
$db->prepare('INSERT INTO graph_templates_graph VALUES (' . implode(',', array_fill(0, count($graph), '?')) . ')')->execute(array_values($graph));
$db->exec('CREATE TABLE graph_local (id INTEGER, host_id INTEGER, snmp_query_id INTEGER, snmp_index TEXT)');
$db->prepare('INSERT INTO graph_local VALUES (?,0,0,"")')->execute(array($graphId));
function db_fetch_row_prepared($sql, $params = array(), ...$args)
{
    if (str_contains($sql, 'FROM graph_templates_graph') or str_contains($sql, 'FROM aggregate_graph_templates_graph')) {
        $statement = $GLOBALS['db']->prepare($sql);
        $statement->execute($params);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }
    return array();
}
function db_fetch_assoc_prepared($sql, $params = array(), ...$args)
{
    return array();
}
function db_fetch_assoc($sql, ...$args)
{
    return array();
}
function db_fetch_cell_prepared($sql, $params = array(), ...$args)
{
    return 0;
}
function db_fetch_cell($sql, ...$args)
{
    return 0;
}
function db_table_exists(...$args)
{
    return false;
}
function db_execute_prepared(...$args)
{
    return true;
}
function sql_save($row, $table)
{
    $columns = array_keys($row);
    $updates = implode(',', array_map(static fn($key) => $key . '=excluded.' . $key, $columns));
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ') ON CONFLICT(id) DO UPDATE SET ' . $updates;
    $GLOBALS['db']->prepare($sql)->execute(array_values($row));
    return (int) $row['id'];
}
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/plugins.php';
require $root . '/lib/auth.php';
require $root . '/include/global_arrays.php';
$no_http_header_files = array();
require $root . '/include/global_settings.php';
require $root . '/lib/html.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/rrd.php';
require $root . '/lib/variables.php';
require $root . '/lib/api_aggregate.php';
if ($graphId === 8) {
    $db->exec('CREATE TABLE aggregate_graph_templates_graph (aggregate_template_id INTEGER, t_unit_exponent_value TEXT, unit_exponent_value TEXT)');
    $db->prepare('INSERT INTO aggregate_graph_templates_graph VALUES (4,"on",?)')->execute(array($exponent));
    $template = $graph;
    $template['id'] = '10';
    $template['local_graph_id'] = '0';
    $template['unit_exponent_value'] = '2';
    $db->prepare('INSERT INTO graph_templates_graph VALUES (' . implode(',', array_fill(0, count($template), '?')) . ')')->execute(array_values($template));
    aggregate_graph_templates_graph_save(8, 9, 'Stored aggregate', 4);
}

ob_start();
rrdtool_function_graph($graphId, 0, array('graph_start' => 1700000000, 'graph_end' => 1700003600, 'print_source' => true));
$output = ob_get_clean();
file_put_contents($directory . '/result.json', json_encode(array('html' => $output, 'stored' => $db->query('SELECT unit_exponent_value FROM graph_templates_graph WHERE local_graph_id=' . $graphId)->fetchColumn()), JSON_THROW_ON_ERROR));
