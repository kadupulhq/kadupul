<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
mkdir($directory . '/include');
mkdir($directory . '/lib');
// Authentication and unrelated mutation APIs are outside this confirmation
// fixture. Production request validation, SQL, rendering and escaping run.
foreach (array('include/auth.php', 'include/global_session.php', 'lib/api_graph.php', 'lib/api_tree.php', 'lib/api_data_source.php', 'lib/api_aggregate.php', 'lib/data_query.php', 'lib/html_form_template.php', 'lib/poller.php', 'lib/reports.php', 'lib/rrd.php', 'lib/template.php', 'lib/utility.php') as $file) {
    file_put_contents($directory . '/' . $file, '<?php');
}
file_put_contents($directory . '/lib/html_tree.php', '<?php require ' . var_export($root . '/lib/html_tree.php', true) . ';');
$controller = $directory . '/aggregate_graphs.php';
copy($root . '/aggregate_graphs.php', $controller);
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $controller);
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/aggregate_graphs.php');
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/html.php';
require $root . '/lib/html_validate.php';
require $root . '/lib/html_form.php';
require $root . '/lib/headers_secure.php';
require $root . '/lib/variables.php';
session_save_path($directory);
session_start();
$_SESSION = array('sess_user_id' => 1);
$_SERVER['SCRIPT_NAME'] = '/aggregate_graphs.php';
$_SERVER['REQUEST_URI'] = '/aggregate_graphs.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST = $_POST = array_merge(array('action' => 'actions', 'header' => 'false', 'drp_action' => '1', 'chk_42' => 'on', '__csrf_magic' => 'fixture'), $scenario);
$_CACTI_REQUEST = array();
$config = array('base_path' => $root, 'cacti_server_os' => 'unix', 'connection' => 'online', 'url_path' => '/', 'poller_id' => 1, 'is_web' => false, 'config_options_array' => array('log_validation' => '', 'selected_theme' => 'classic', 'autocomplete_enabled' => '', 'hide_form_description' => 'off'));
$no_session_write = array('aggregate_graphs.php');
$no_http_header_files = array();
$alignment = array(1 => 'Center');
$graph_timespans = array(1 => 'Last day');
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec("CREATE TABLE graph_tree(id INTEGER,name TEXT);
    CREATE TABLE graph_tree_items(id INTEGER,title TEXT,parent INTEGER,graph_tree_id INTEGER,host_id INTEGER,local_graph_id INTEGER,position INTEGER);
    CREATE TABLE graph_local(id INTEGER,host_id INTEGER,snmp_query_id INTEGER,snmp_index TEXT);
    CREATE TABLE graph_templates_graph(id INTEGER,local_graph_id INTEGER,title TEXT,title_cache TEXT,t_title TEXT,graph_template_id INTEGER DEFAULT 0,height INTEGER DEFAULT 100,width INTEGER DEFAULT 200);
    CREATE TABLE graph_templates_item(id INTEGER,local_graph_id INTEGER,task_item_id INTEGER,graph_template_id INTEGER);
    CREATE TABLE aggregate_graph_templates(id INTEGER,name TEXT,graph_template_id INTEGER);
    CREATE TABLE graph_templates(id INTEGER,name TEXT);
    CREATE TABLE reports(id INTEGER,name TEXT,user_id INTEGER);
    CREATE TABLE settings_user(user_id INTEGER,name TEXT,value TEXT);
    CREATE TABLE settings(name TEXT,value TEXT);
    CREATE TABLE aggregate_graphs(id INTEGER,local_graph_id INTEGER,title_format TEXT,aggregate_template_id INTEGER,graph_template_id INTEGER);
    CREATE TABLE aggregate_graphs_items(aggregate_graph_id INTEGER,local_graph_id INTEGER);
    CREATE TABLE user_auth_row_cache(user_id INTEGER,class TEXT,hash TEXT,total_rows INTEGER,time TEXT);
    INSERT INTO graph_local VALUES(42,0,0,'');
    INSERT INTO graph_local VALUES(43,0,0,'');
    INSERT INTO graph_templates_graph(id,local_graph_id,title,title_cache,t_title) VALUES(1,42,'Graph <x>title</x>','Graph title','');
    INSERT INTO graph_templates_graph(id,local_graph_id,title,title_cache,t_title) VALUES(2,43,'Other graph','Other graph','');
    INSERT INTO aggregate_graphs VALUES(9,42,'Aggregate',2,0);
    INSERT INTO aggregate_graphs VALUES(10,43,'Unchanged aggregate',2,0);
    INSERT INTO aggregate_graphs_items VALUES(9,42);
    INSERT INTO graph_templates_item VALUES(1,42,7,3);
    INSERT INTO aggregate_graph_templates VALUES(2,'Aggregate <x>template</x>',3);
    INSERT INTO graph_templates VALUES(3,'Template');
    INSERT INTO reports VALUES(4,'Report',1);
    INSERT INTO graph_tree VALUES(6,'Tree');
    INSERT INTO graph_tree_items VALUES(8,'Branch',0,6,0,0,1);");
$db->sqliteCreateFunction('UNIX_TIMESTAMP', static fn($value) => strtotime($value), 1);
$db->sqliteCreateFunction('FROM_UNIXTIME', static fn($value) => gmdate('Y-m-d H:i:s', $value), 1);
function __($text, ...$arguments)
{
    return $arguments ? vsprintf($text, $arguments) : $text;
}
function __esc($text, ...$arguments)
{
    return html_escape(__($text, ...$arguments));
}
function csrf_check($fatal = true)
{
    return true;
}
function __x($context, $text, ...$arguments)
{
    return __($text, ...$arguments);
}
function get_installed_locales()
{
    return array('en-US' => 'English');
}
function get_new_user_default_language()
{
    return 'en-US';
}
function db_table_exists($table, $log = true, $connection = false)
{
    return false;
}
function aggregate_prune_graphs($id = 0) {}
// Graph generation is an unrelated mutation boundary. The controller's
// parameter handoff and subsequent aggregate-title SQL update remain real.
function aggregate_graph_templates_graph_save(...$arguments)
{
    $GLOBALS['aggregate_graph_save_arguments'] = $arguments;
    return 1;
}
function db_close() {}
function api_plugin_hook($name, ...$arguments) {}
function api_plugin_hook_function($name, $value)
{
    return $value;
}
function aggregate_native_query($sql, $params = array())
{
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function db_fetch_assoc($sql)
{
    return aggregate_native_query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_assoc_prepared($sql, $params)
{
    return aggregate_native_query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_row_prepared($sql, $params)
{
    return aggregate_native_query($sql, $params)->fetch(PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $params)
{
    return aggregate_native_query($sql, $params)->fetchColumn();
}
function db_fetch_cell($sql)
{
    return aggregate_native_query($sql)->fetchColumn();
}
function db_qstr($value)
{
    return $GLOBALS['db']->quote((string) $value);
}
function db_execute_prepared($sql, $params)
{
    aggregate_native_query($sql, $params);
    return true;
}
require $root . '/lib/auth.php';
require $root . '/include/global_arrays.php';
require $root . '/include/global_settings.php';
require $root . '/include/global_form.php';
$config['base_path'] = $directory;
ob_start();
register_shutdown_function(function () {
    fwrite(STDOUT, json_encode(array('html' => ob_get_clean(), 'title' => db_fetch_cell_prepared('SELECT title_format FROM aggregate_graphs WHERE id = ?', array(9)), 'other_title' => db_fetch_cell_prepared('SELECT title_format FROM aggregate_graphs WHERE id = ?', array(10)), 'save' => $GLOBALS['aggregate_graph_save_arguments'] ?? null), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
});
chdir($directory);
require $controller;
