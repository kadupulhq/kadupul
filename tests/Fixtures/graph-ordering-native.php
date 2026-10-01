<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = $argv[1];
$directory = $argv[2];
$scenario = $argv[3];
$controller = str_starts_with($scenario, 'templates-') ? 'graph_templates_items.php' : 'graphs_items.php';
if (getenv('NATIVE_ORDERING_COVERAGE') === '1') {
    define('THEME_SELECTION_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    if (str_starts_with($scenario, 'templates-') || str_starts_with($scenario, 'graphs-')) {
        define('RRD_TEST_CLI_COVERAGE_COPY', $directory . '/' . $controller);
        define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/' . $controller);
    }
    $errorLevel = error_reporting();
    error_reporting($errorLevel & ~E_DEPRECATED);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
    error_reporting($errorLevel);
}
// MySQL uses connection-local temporary tables and never changes installed tables.
$mysql = getenv('NATIVE_ORDERING_MYSQL') === '1';
$database = $mysql ? new PDO(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '') : new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$tablePrefix = $mysql ? 'CREATE TEMPORARY TABLE ' : 'CREATE TABLE ';
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec($tablePrefix . 'graph_templates_item (id INTEGER PRIMARY KEY, sequence INTEGER, graph_template_id INTEGER, local_graph_id INTEGER, local_graph_template_item_id INTEGER DEFAULT 0, graph_type_id INTEGER, text_format VARCHAR(255) DEFAULT "", hard_return VARCHAR(2) DEFAULT "", task_item_id INTEGER DEFAULT 0, hash VARCHAR(64) DEFAULT "")');
$database->exec($tablePrefix . 'graph_template_input (id INTEGER PRIMARY KEY, graph_template_id INTEGER, name TEXT, column_name TEXT)');
$database->exec($tablePrefix . 'graph_template_input_defs (graph_template_input_id INTEGER, graph_template_item_id INTEGER)');
$database->exec('INSERT INTO graph_templates_item (id,sequence,graph_template_id,local_graph_id,graph_type_id) VALUES (1,1,2,0,9),(2,2,2,0,9),(3,1,2,3,9),(4,2,2,3,9),(5,1,2,4,9),(6,50,2,4,9),(7,1,8,0,9),(8,2,8,0,9)');
$queries = array();
$saved = array();
function native_order_statement($sql, $params)
{
    $GLOBALS['queries'][] = array($sql, $params);
    $statement = $GLOBALS['database']->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function db_fetch_cell_prepared($sql, $params = array())
{
    return native_order_statement($sql, $params)->fetchColumn();
}
function db_fetch_cell($sql)
{
    return db_fetch_cell_prepared($sql);
}
function db_fetch_row_prepared($sql, $params = array())
{
    return native_order_statement($sql, $params)->fetch(PDO::FETCH_ASSOC) ?: array();
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    return native_order_statement($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
function db_execute_prepared($sql, $params = array())
{
    native_order_statement($sql, $params);
    return true;
}
// Replication and downstream graph regeneration are separate boundaries.
function resequence_graphs_simple($id) {}
function push_out_graph_item($id, $changed) {}
function sql_save($values, $table)
{
    $GLOBALS['saved'][] = $values;
    $id = $values['id'] ?: 20;
    db_execute_prepared('REPLACE INTO graph_templates_item (id,sequence,graph_template_id,local_graph_id,graph_type_id) VALUES (?,?,?,?,?)', array($id, $values['sequence'], $values['graph_template_id'], $values['local_graph_id'], $values['graph_type_id']));
    return $id;
}
function __($text, ...$values)
{
    return $values ? sprintf($text, ...$values) : $text;
}
$config = array('base_path' => $directory, 'is_web' => false, 'url_path' => '/', 'config_options_array' => array('log_validation' => '', 'auth_method' => 1));
$graph_item_types = array(4 => 'LINE1', 5 => 'LINE2', 6 => 'LINE3', 9 => 'GPRINT');
require $root . '/include/global_constants.php';
$messages = array(1 => array('message' => 'Saved', 'level' => MESSAGE_LEVEL_INFO), 2 => array('message' => 'Failed', 'level' => MESSAGE_LEVEL_ERROR));
require $root . '/lib/functions.php';
require $root . '/lib/html_utility.php';
session_save_path($directory);
session_start();
$_SESSION['sess_user_id'] = 7;
$_SESSION['sess_messages'] = array();
$before = $database->query('SELECT id,sequence FROM graph_templates_item ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
register_shutdown_function(function () use ($database, &$before) {
    $after = $database->query('SELECT id,sequence FROM graph_templates_item ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    fwrite(STDOUT, json_encode(array('before' => $before, 'after' => $after, 'queries' => $GLOBALS['queries'], 'saved' => $GLOBALS['saved'], 'result' => $GLOBALS['result'] ?? null), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
});
if (str_starts_with($scenario, 'sequence-')) {
    $filters = match ($scenario) {
        'sequence-template' => array('graph_template_id' => 2, 'local_graph_id' => 0),
        'sequence-local' => array('local_graph_id' => 3),
        'sequence-injection' => array('local_graph_id' => '3 OR 1=1'),
        default => array('local_graph_id' => 99),
    };
    $GLOBALS['result'] = get_sequence(0, 'sequence', 'graph_templates_item', $filters);
    exit;
}
if (str_starts_with($scenario, 'group-')) {
    $local = str_contains($scenario, 'local');
    $base = $local ? 3 : 1;
    $scope = $local ? 3 : 0;
    $database->exec('DELETE FROM graph_templates_item WHERE id IN (' . $base . ',' . ($base + 1) . ')');
    $insert = $database->prepare('INSERT INTO graph_templates_item (id,sequence,graph_template_id,local_graph_id,graph_type_id,hard_return) VALUES (?,?,?,?,?,?)');
    foreach (array(array($base,1,4,''), array($base + 1,2,9,'on'),array(11,3,4,''),array(12,4,9,'on')) as [$id,$sequence,$type,$hard]) {
        $insert->execute(array($id,$sequence,2,$scope,$type,$hard));
    }
    $before = $database->query('SELECT id,sequence FROM graph_templates_item ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $previous = str_ends_with($scenario, 'previous');
    $current = $previous ? 11 : $base;
    $target = $previous ? $base : 11;
    move_graph_group($current, get_graph_group($current), $target, $previous ? 'previous' : 'next');
    exit;
}
$local = str_starts_with($scenario, 'graphs-');
$up = str_ends_with($scenario, 'up');
$id = $local ? ($up ? 4 : 3) : ($up ? 2 : 1);
if (str_contains($scenario, 'boundary')) {
    $id = $local ? ($up ? 3 : 4) : ($up ? 1 : 2);
}
$_REQUEST = array('action' => str_contains($scenario, 'save') ? 'save' : ($up ? 'item_moveup' : 'item_movedown'), 'id' => $id, 'graph_template_id' => 2, 'local_graph_id' => 3, 'graph_template_item_id' => 0, 'local_graph_template_item_id' => 0, 'save_component_item' => 1, 'sequence' => 0, 'graph_type_id' => 9, 'task_item_id' => 0, 'color_id' => 0, 'alpha' => 'FF', 'cdef_id' => 0, 'vdef_id' => 0, 'consolidation_function_id' => 4, 'gprint_id' => 0, 'text_format' => '', 'value' => '');
$_POST = $_REQUEST;
chdir($directory);
require $directory . '/' . $controller;
