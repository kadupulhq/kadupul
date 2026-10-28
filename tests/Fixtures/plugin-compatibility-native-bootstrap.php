<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$scenario = json_decode(getenv('PLUGIN_COMPAT_SCENARIO'), true, 512, JSON_THROW_ON_ERROR);
$config = array('base_path' => getenv('PLUGIN_COMPAT_DIRECTORY'), 'library_path' => getenv('PLUGIN_COMPAT_DIRECTORY') . '/lib', 'url_path' => '/', 'poller_id' => 1);
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
    // SQLite's equivalent of MySQL's UPDATE IGNORE preserves conflict semantics.
    $sql = str_replace('UPDATE IGNORE ', 'UPDATE OR IGNORE ', $sql);
    if (preg_match('/DELETE (ph|pd)\s+FROM (plugin_hooks|plugin_db_changes) AS (ph|pd)/', $sql, $match)) {
        $column = $match[2] === 'plugin_hooks' ? 'name' : 'plugin';
        $sql = 'DELETE FROM ' . $match[2] . ' WHERE ' . $column . ' NOT IN (SELECT directory FROM plugin_config) AND ' . $column . ' != ?';
    }
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
function db_fetch_assoc($sql, ...$args)
{
    if ($sql === 'SHOW TABLES') {
        return $GLOBALS['db']->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC);
    }
    if (preg_match('/SHOW COLUMNS FROM `([^`]+)`/i', $sql, $match)) {
        return array_map(fn($row) => array('Field' => $row['name']), $GLOBALS['db']->query('PRAGMA table_info(`' . $match[1] . '`)')->fetchAll(PDO::FETCH_ASSOC));
    }
    return str_contains($sql, 'FROM poller') ? array() : plugin_compat_query($sql, array())->fetchAll(PDO::FETCH_ASSOC);
}
function db_execute($sql, ...$args)
{
    $GLOBALS['writes'][] = array($sql, array());
    $sql = preg_replace('/\) ENGINE = \w+$/', ')', $sql);
    return $GLOBALS['db']->exec($sql) !== false;
}
function db_fetch_cell($sql, ...$args)
{
    if (preg_match("/^SHOW TABLES LIKE '(\\w+)'$/", $sql, $match)) {
        return plugin_compat_query("SELECT name FROM sqlite_master WHERE type='table' AND name = ?", array($match[1]))->fetchColumn();
    }
    return plugin_compat_query($sql, array())->fetchColumn();
}
function cacti_count($value)
{
    return cacti_sizeof($value);
}
function db_fetch_cell_prepared($sql, $params = array())
{
    return plugin_compat_query($sql, $params)->fetchColumn() ?: '';
}
function array_rekey($rows, ...$args)
{
    return $rows;
}
function read_config_option($key, ...$args)
{
    return $key === 'admin_user' ? 1 : 300;
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
// The plugin DDL wrappers delegate to the validated helpers in lib/database.php;
// load those as written, with the SQLite handle as the default connection.
require_once getenv('PLUGIN_COMPAT_ROOT') . '/tests/Helpers/PhpSource.php';
require_once getenv('PLUGIN_COMPAT_ROOT') . '/src/Platform/Infrastructure/Legacy/LegacyConnectionRegistry.php';
$database_hostname = 'localhost';
$database_port = '3306';
$database_default = 'cacti';
$database_sessions = array('localhost:3306:cacti' => $db);
$plugin_compat_sources = array(
    'lib/database.php' => array('db_has_control_chars', 'db_is_safe_identifier', 'db_is_safe_column_type', 'db_is_safe_table_option', 'db_is_safe_index_column', 'db_is_safe_column_definition', 'db_build_column_definition_sql', 'db_is_safe_table_definition', 'db_format_index_create', 'db_qstr', 'db_table_exists', 'db_table_create', 'db_add_column'),
);
foreach ($plugin_compat_sources as $file => $functions) {
    $source = file_get_contents(getenv('PLUGIN_COMPAT_ROOT') . '/' . $file);
    foreach ($functions as $function) {
        if (str_contains($source, 'function ' . $function . '(')) {
            eval(test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
        }
    }
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

if (($scenario['mode'] ?? '') === 'api') {
    require getenv('PLUGIN_COMPAT_ROOT') . '/tests/Fixtures/plugin-api-native.php';
    exit;
}
