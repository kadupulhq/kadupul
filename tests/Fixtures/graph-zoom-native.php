<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = getenv('GRAPH_ZOOM_NATIVE_ROOT');
$directory = getenv('GRAPH_ZOOM_NATIVE_DIRECTORY');
$scenario = json_decode(getenv('GRAPH_ZOOM_NATIVE_SCENARIO'), true, 512, JSON_THROW_ON_ERROR);
if (getenv('GRAPH_ZOOM_NATIVE_COVERAGE') === '1') {
    define('GRAPH_ZOOM_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $directory . '/graph.php');
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/graph.php');
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/html.php';
require $root . '/lib/headers_secure.php';
require $root . '/lib/rrd.php';
require $root . '/lib/graph_zoom.php';
session_start();
$_SESSION = array('sess_user_id' => 1, 'sess_user_config_array' => array('custom_fonts' => '', 'show_graph_title' => '', 'page_refresh' => 30));
$_REQUEST = $_GET = $_POST = array_merge(array('action' => 'zoom', 'local_graph_id' => 4, 'rra_id' => 'all'), $scenario['request'] ?? array());
$_CACTI_REQUEST = array();
$_SERVER['SCRIPT_NAME'] = '/graph.php';
$config = array('base_path' => $directory, 'url_path' => '/cacti/', 'poller_id' => 1, 'is_web' => false, 'config_options_array' => array('log_validation' => '', 'title_size' => 10, 'realtime_enabled' => $scenario['realtime_enabled'] ?? ''));
$no_session_write = array('graph.php');
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec("CREATE TABLE graph_templates_graph(local_graph_id INTEGER,width INTEGER,height INTEGER,title_cache TEXT,graph_template_id INTEGER,title TEXT,t_title TEXT);
    CREATE TABLE graph_local(id INTEGER,host_id INTEGER,snmp_query_id INTEGER,snmp_index TEXT);
    CREATE TABLE host(id INTEGER,disabled TEXT);
    CREATE TABLE graph_templates_item(local_graph_id INTEGER,task_item_id INTEGER);
    CREATE TABLE data_template_rrd(id INTEGER,local_data_id INTEGER);
    CREATE TABLE data_template_data(local_data_id INTEGER,data_source_profile_id INTEGER,rrd_step INTEGER);
    CREATE TABLE data_source_profiles(id INTEGER,step INTEGER);
    CREATE TABLE data_source_profiles_rra(id INTEGER,data_source_profile_id INTEGER,steps INTEGER,rows INTEGER,name TEXT,timespan INTEGER);
    INSERT INTO graph_templates_graph VALUES (4,400,100,'Native Graph',3,'Native Graph','');
    INSERT INTO graph_local VALUES (4,1,0,'');
    INSERT INTO host VALUES (1,'');
    INSERT INTO graph_templates_item VALUES (4,6);
    INSERT INTO data_template_rrd VALUES (6,8);
    INSERT INTO data_template_data VALUES (8,2,300);
    INSERT INTO data_source_profiles VALUES (2,300),(3,300);
    INSERT INTO data_source_profiles_rra VALUES (7,2,12,100,'Long',0),(5,2,1,10,'Short',0),(12,3,1,100,'Other profile',0);");
if (!empty($scenario['no_profile'])) {
    $db->exec('UPDATE data_template_data SET data_source_profile_id=NULL');
}
if (!empty($scenario['missing_graph_row'])) {
    $db->exec('DELETE FROM graph_local');
}
$queries = array();
function __($text, ...$arguments)
{
    return $arguments ? vsprintf($text, $arguments) : $text;
}
function __esc($text, ...$arguments)
{
    return html_escape(__($text, ...$arguments));
}
function api_plugin_hook_function($name, ...$arguments) {}
function api_plugin_hook($name, ...$arguments) {}
function is_graph_allowed($id)
{
    return true;
}
function is_realm_allowed($realm)
{
    return in_array($realm, $GLOBALS['scenario']['realms'] ?? array(), true);
}
function aggregate_build_children_url(...$arguments)
{
    return '';
}
function db_close() {}
function graph_zoom_native_query($sql, $params)
{
    $GLOBALS['queries'][] = array($sql, $params);
    if (!empty($GLOBALS['scenario']['deleted_rra']) && str_starts_with($sql, 'SELECT dspr.id')) {
        $GLOBALS['db']->exec('DELETE FROM data_source_profiles_rra WHERE id=5');
    }
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function db_fetch_cell_prepared($sql, $params, ...$arguments)
{
    return graph_zoom_native_query($sql, $params)->fetchColumn();
}
function db_fetch_row_prepared($sql, $params, ...$arguments)
{
    return graph_zoom_native_query($sql, $params)->fetch(PDO::FETCH_ASSOC);
}
function db_fetch_assoc_prepared($sql, $params, ...$arguments)
{
    return graph_zoom_native_query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
register_shutdown_function(function () use ($directory) {
    file_put_contents($directory . '/state.json', json_encode(array('session' => $_SESSION, 'queries' => $GLOBALS['queries']), JSON_THROW_ON_ERROR));
});
