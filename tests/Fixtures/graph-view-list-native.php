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
foreach (array('include', 'lib') as $folder) {
    mkdir($directory . '/' . $folder);
}
mkdir($directory . '/include/themes/classic', 0700, true);
file_put_contents($directory . '/include/themes/classic/main.css', '');
$themes = array('classic' => 'Classic');
// Isolate authentication and unrelated page bootstraps. The controller and
// request/session, escaping and rendering libraries run unchanged.
foreach (array('include/auth.php', 'include/global_session.php', 'lib/html_tree.php', 'lib/html_graph.php', 'lib/api_tree.php', 'lib/graphs.php', 'lib/reports.php', 'lib/timespan_settings.php') as $file) {
    file_put_contents($directory . '/' . $file, '<?php');
}
$controller = $directory . '/graph_view.php';
copy($root . '/graph_view.php', $controller);
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[3]);
    define('RRD_TEST_CLI_COVERAGE_COPY', $controller);
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/graph_view.php');
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/html.php';
require $root . '/lib/html_validate.php';
require $root . '/lib/html_form.php';
require $root . '/lib/headers_secure.php';
$_SERVER['SCRIPT_NAME'] = '/graph_view.php';
$_SERVER['REQUEST_URI'] = '/graph_view.php?action=list&page=1';
$_SESSION = array_merge(array('sess_user_id' => 1), $scenario['session'] ?? array());
$_GET = $_POST = $_REQUEST = array_merge(array('action' => 'list', 'header' => 'false'), $scenario['request']);
$_CACTI_REQUEST = array();
$config = array('base_path' => $directory, 'url_path' => '/', 'poller_id' => 1, 'is_web' => false, 'config_options_array' => array('log_validation' => '', 'num_rows_table' => 10, 'selected_theme' => 'classic', 'autocomplete_enabled' => '', 'hide_form_description' => 'off'));
$no_session_write = array('graph_view.php');
$item_rows = array(10 => '10', 20 => '20');
$graph_timespans = $alignment = $graph_sources = array();
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec("CREATE TABLE reports(id INTEGER,user_id INTEGER,name TEXT);
    CREATE TABLE settings_user(user_id INTEGER,name TEXT,value TEXT);
    CREATE TABLE host(id INTEGER,site_id INTEGER,location TEXT,description TEXT,hostname TEXT);
    CREATE TABLE graph_local(id INTEGER,graph_template_id INTEGER);
    CREATE TABLE graph_templates_graph(local_graph_id INTEGER,title_cache TEXT);
    CREATE TABLE sites(id INTEGER,name TEXT);
    CREATE TABLE graph_tree_items(id INTEGER,parent INTEGER,graph_tree_id INTEGER,host_id INTEGER,site_id INTEGER,local_graph_id INTEGER,title TEXT);
    INSERT INTO host VALUES(1,2,'Lab','Device','example.test');
    INSERT INTO graph_local VALUES(7,3);
    INSERT INTO graph_tree_items VALUES(1,0,4,0,0,0,'Root'),(2,1,4,1,2,7,'Child');");
function __($text, ...$arguments)
{
    return $arguments ? vsprintf($text, $arguments) : $text;
}
function __esc($text, ...$arguments)
{
    return html_escape(__($text, ...$arguments));
}
function set_default_graph_action() {}
function process_tree_settings() {}
function initialize_realtime_step_and_window() {}
function is_view_allowed($view)
{
    return true;
}
function is_realm_allowed($realm)
{
    return false;
}
function get_allowed_devices(...$arguments)
{
    return array();
}
function get_allowed_sites(...$arguments)
{
    return array();
}
function get_allowed_graph_templates(...$arguments)
{
    return array(array('id' => 3, 'name' => 'Template'));
}
function get_allowed_graphs($where, $order, $limit, &$total)
{
    $total = 0;
    return array();
}
function api_plugin_hook($name, ...$arguments) {}
function api_plugin_hook_function($name, $value)
{
    return $value;
}
function db_qstr($value)
{
    return $GLOBALS['db']->quote((string) $value);
}
function db_close() {}
function api_tree_get_main($treeId, $parent)
{
    echo json_encode(array('tree_id' => (int) $treeId, 'parent' => (int) $parent), JSON_THROW_ON_ERROR);
}
function graph_view_native_query($sql, $params)
{
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function db_fetch_assoc($sql)
{
    return graph_view_native_query($sql, array())->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_assoc_prepared($sql, $params)
{
    return graph_view_native_query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $params)
{
    return graph_view_native_query($sql, $params)->fetchColumn();
}
function db_fetch_row_prepared($sql, $params)
{
    return graph_view_native_query($sql, $params)->fetch(PDO::FETCH_ASSOC);
}
ob_start();
register_shutdown_function(function () {
    fwrite(STDOUT, json_encode(array('html' => ob_get_clean(), 'request' => $_REQUEST, 'session' => $_SESSION), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
});
chdir($directory);
require $controller;
