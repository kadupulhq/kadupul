<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
$config = array('url_path' => '/kadupul/');
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$queries = array();

function clog_native_query($sql, $values = array())
{
    $GLOBALS['queries'][] = array('sql' => $sql, 'values' => $values);
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($values);
    return $statement;
}
function db_fetch_assoc_prepared($sql, $values)
{
    return clog_native_query($sql, $values)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_assoc($sql)
{
    return db_fetch_assoc_prepared($sql, array());
}
function db_fetch_cell_prepared($sql, $values)
{
    return clog_native_query($sql, $values)->fetchColumn();
}
function db_fetch_row_prepared($sql, $values)
{
    return clog_native_query($sql, $values)->fetch(PDO::FETCH_ASSOC) ?: array();
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function __($text)
{
    return $text;
}
function api_plugin_hook_function($hook, $value)
{
    $GLOBALS['hooks'][] = $hook;
    return $value;
}

// Use the actual compatibility helpers; coverage claims concern the directly
// included clog source, not these token-extracted dependencies.
require $root . '/tests/Helpers/PhpSource.php';
foreach (array('array_rekey', 'get_data_source_title') as $function) {
    eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), $function));
}
eval(test_php_function_source(file_get_contents($root . '/lib/html.php'), 'html_escape'));

foreach (array(
    'host' => 'id INTEGER PRIMARY KEY, description TEXT',
    'poller' => 'id INTEGER PRIMARY KEY, name TEXT',
    'snmp_query' => 'id INTEGER PRIMARY KEY, name TEXT',
    'graph_templates' => 'id INTEGER PRIMARY KEY, name TEXT',
    'automation_graph_rules' => 'id INTEGER PRIMARY KEY, name TEXT',
    'data_input' => 'id INTEGER PRIMARY KEY, name TEXT',
    'user_auth' => 'id INTEGER PRIMARY KEY, username TEXT',
    'graph_templates_graph' => 'local_graph_id INTEGER PRIMARY KEY, title_cache TEXT',
    'graph_templates_item' => 'local_graph_id INTEGER, task_item_id INTEGER',
    'data_template_rrd' => 'id INTEGER PRIMARY KEY, local_data_id INTEGER',
    'data_local' => 'id INTEGER PRIMARY KEY, host_id INTEGER, snmp_query_id INTEGER, snmp_index TEXT, data_template_id INTEGER',
    'data_template_data' => 'id INTEGER PRIMARY KEY, local_data_id INTEGER, name TEXT'
) as $table => $columns) {
    $db->exec('CREATE TABLE ' . $table . ' (' . $columns . ')');
}
$label = 'Name <tag> & "quote"';
foreach (array('host' => 'description', 'poller' => 'name', 'snmp_query' => 'name', 'graph_templates' => 'name', 'automation_graph_rules' => 'name', 'data_input' => 'name', 'user_auth' => 'username') as $table => $column) {
    $db->prepare('INSERT INTO ' . $table . ' (id, ' . $column . ') VALUES (?, ?)')->execute(array(7, $label));
}
$db->prepare('INSERT INTO graph_templates_graph VALUES (?, ?)')->execute(array(17, $label));
$db->exec('INSERT INTO graph_templates_item VALUES (17, 27), (17, 27), (0, 27)');
$db->exec('INSERT INTO data_template_rrd VALUES (27, 7)');
$db->exec('INSERT INTO data_local VALUES (7, 0, 0, "", 0)');
$db->prepare('INSERT INTO data_template_data VALUES (37, 7, ?)')->execute(array($label));
$queries = array();
$hooks = array();
if (($argv[3] ?? '') === 'coverage') {
    define('CLOG_LINKS_NATIVE_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/lib/clog_webapi.php';
$regex = clog_get_regex_array();
$input = $scenario['input'];
$first = preg_replace_callback($regex['complete'], 'clog_regex_parser', $input);
$firstQueries = count($queries);
$second = preg_replace_callback($regex['complete'], 'clog_regex_parser', $input);
$graphs = clog_get_graphs_from_datasource(7);
define('NATIVE_COVERAGE_COMPLETED', array('clog-links-rendered', 'clog-links-repeat-rendered', 'clog-graph-query-observed'));
echo json_encode(array('first' => $first, 'second' => $second, 'firstQueries' => $firstQueries, 'repeatQueries' => count($queries) - $firstQueries - 1, 'graphs' => $graphs, 'queries' => $queries, 'hooks' => $hooks), JSON_THROW_ON_ERROR);
