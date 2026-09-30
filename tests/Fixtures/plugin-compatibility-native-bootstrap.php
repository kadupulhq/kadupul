<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$scenario = json_decode(getenv('PLUGIN_COMPAT_SCENARIO'), true, 512, JSON_THROW_ON_ERROR);
$config = array('base_path' => getenv('PLUGIN_COMPAT_DIRECTORY'), 'url_path' => '/', 'poller_id' => 1);
define('CACTI_VERSION', '1.3.0');
define('MESSAGE_LEVEL_ERROR', 2);
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec('CREATE TABLE plugin_config(id INTEGER PRIMARY KEY, directory TEXT, status INTEGER DEFAULT 0, name TEXT, author TEXT, webpage TEXT, version TEXT)');
$writes = array();
$messages = array();
$plugins_integrated = array();
$_SESSION = array('sess_plugins_state' => 0);
function __($message, ...$arguments)
{
    return $arguments ? vsprintf($message, $arguments) : $message;
}
function __esc($message, ...$arguments)
{
    return htmlspecialchars(__($message, ...$arguments), ENT_QUOTES);
}
function html_escape($value)
{
    return htmlspecialchars($value, ENT_QUOTES);
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log(...$arguments) {}
function cacti_version_compare($left, $right, $operator = '>')
{
    return version_compare($left, $right, $operator);
}
function plugin_compat_query($sql, $params)
{
    $stmt = $GLOBALS['db']->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
function db_fetch_row_prepared($sql, $params = array())
{
    return plugin_compat_query($sql, $params)->fetch(PDO::FETCH_ASSOC);
}
function db_execute_prepared($sql, $params = array())
{
    $GLOBALS['writes'][] = array($sql, $params);
    if (empty($GLOBALS['scenario']['persist_fail'])) {
        plugin_compat_query($sql, $params);
    }
    return true;
}
function db_fetch_assoc_prepared($sql, $params = array(), ...$args)
{
    return plugin_compat_query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_assoc(...$args)
{
    return array();
}
function db_fetch_cell_prepared($sql, $params = array())
{
    return plugin_compat_query($sql, $params)->fetchColumn() ?: '';
}
function array_rekey($rows, ...$args)
{
    return $rows;
}
function read_config_option(...$args)
{
    return 300;
}
function raise_message($key, $message, ...$args)
{
    $GLOBALS['messages'][$key] = $message;
}
function cacti_plugin_path($plugin, $relative)
{
    return $GLOBALS['config']['base_path'] . '/plugins/' . $plugin . '/' . $relative;
}
function get_nfilter_request_var($key)
{
    return $_REQUEST[$key] ?? '';
}
function get_filter_request_var($key, ...$args)
{
    return get_nfilter_request_var($key);
}
function get_request_var($key)
{
    return get_nfilter_request_var($key);
}
function isset_request_var($key)
{
    return isset($_REQUEST[$key]);
}
function sanitize_search_string($value)
{
    return $value;
}
function cacti_require_post_request()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Native install must use POST');
    }
}
if (defined('PLUGIN_COMPAT_TEST_COVERAGE')) {
    require getenv('PLUGIN_COMPAT_ROOT') . '/tests/Fixtures/rrd-process-coverage.php';
}
require getenv('PLUGIN_COMPAT_ROOT') . '/lib/plugins.php';
register_shutdown_function(function () {
    $report = array('writes' => $GLOBALS['writes'], 'messages' => $GLOBALS['messages'], 'setup' => file_exists($GLOBALS['config']['base_path'] . '/setup-ran'), 'install_defined' => defined('IN_CACTI_INSTALL'), 'installed' => $GLOBALS['db']->query('SELECT directory,status FROM plugin_config')->fetchAll(PDO::FETCH_ASSOC));
    file_put_contents($GLOBALS['config']['base_path'] . '/result.json', json_encode($report, JSON_THROW_ON_ERROR));
});
if (($scenario['mode'] ?? '') === 'render') {
    echo plugin_actions(array('status' => 0, 'directory' => 'fixture', 'infoname' => 'Fixture'), 'plugin_config');
    exit;
}
