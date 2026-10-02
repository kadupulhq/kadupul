<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Execute the production report helpers and authorization against SQL tables.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
$config = array('base_path' => $root);
$alignment = array();
$attach_types = array();
$reports_interval = array();
$options = $scenario['options'] ?? array();
$request = array('id' => 7, 'tab' => 'items', 'item_id' => 70, 'report_item' => array('line71', 'line70', 'line80'));
$_SESSION['sess_user_id'] = $scenario['user'] ?? 42;
$_SERVER['REQUEST_METHOD'] = 'POST';
$messages = array();
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)');
$db->exec('CREATE TABLE user_auth_group (id INTEGER, enabled TEXT)');
$db->exec('CREATE TABLE user_auth_group_members (user_id INTEGER, group_id INTEGER)');
$db->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
$db->exec('CREATE TABLE reports_items (id INTEGER PRIMARY KEY AUTOINCREMENT, report_id INTEGER, item_type INTEGER, host_template_id INTEGER, site_id INTEGER, host_id INTEGER, graph_template_id INTEGER, local_graph_id INTEGER, timespan INTEGER, align INTEGER, sequence INTEGER)');
$db->exec('CREATE TABLE host (id INTEGER PRIMARY KEY, description TEXT, host_template_id INTEGER, site_id INTEGER)');
$db->exec('CREATE TABLE graph_local (id INTEGER PRIMARY KEY, host_id INTEGER, graph_template_id INTEGER)');
$db->exec("INSERT INTO host VALUES (100, 'Router <one>', 3, 4)");
$db->exec('INSERT INTO graph_local VALUES (200, 100, 6)');
$db->exec('INSERT INTO reports_items (id, report_id, sequence) VALUES (70, 7, 1), (71, 7, 2), (80, 8, 1)');
if (isset($scenario['realm'])) {
    $db->prepare('INSERT INTO user_auth_realm VALUES (?, ?)')->execute(array($_SESSION['sess_user_id'], $scenario['realm']));
}
if (isset($scenario['group'])) {
    $db->prepare('INSERT INTO user_auth_group VALUES (5, ?)')->execute(array($scenario['group']));
    $db->prepare('INSERT INTO user_auth_group_members VALUES (?, 5)')->execute(array($_SESSION['sess_user_id']));
    $db->exec('INSERT INTO user_auth_group_realm VALUES (5, 21)');
}
function db_fetch_row_prepared($sql, $params = array())
{
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetch(PDO::FETCH_ASSOC) ?: array();
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $params = array())
{
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetchColumn();
}
function db_execute_prepared($sql, $params = array())
{
    return $GLOBALS['db']->prepare($sql)->execute($params);
}
function db_table_exists($table)
{
    return (bool) db_fetch_cell_prepared('SELECT 1 FROM sqlite_master WHERE type = ? AND name = ?', array('table', $table));
}
function read_config_option($name)
{
    return $GLOBALS['options'][$name] ?? '';
}
function get_request_var($name)
{
    return $GLOBALS['request'][$name] ?? '';
}
function get_nfilter_request_var($name)
{
    return get_request_var($name);
}
function get_filter_request_var($name)
{
    return (int) get_request_var($name);
}
function isset_request_var($name)
{
    return array_key_exists($name, $GLOBALS['request']);
}
function set_request_var($name, $value)
{
    $GLOBALS['request'][$name] = $value;
}
function sanitize_search_string($text)
{
    return $text;
}
function input_validate_input_number($value)
{
    if (!ctype_digit((string) $value)) {
        throw new InvalidArgumentException('Invalid fixture item id.');
    }
}
function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}
function __($text, ...$args)
{
    return $args ? vsprintf($text, $args) : $text;
}
function cacti_log(...$args) {}
function raise_message($key, ...$args)
{
    $GLOBALS['messages'][] = $key;
}
function sql_save($save, $table)
{
    unset($save['id']);
    $fields = array_keys($save);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $fields) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')';
    $GLOBALS['db']->prepare($sql)->execute(array_values($save));
    return (int) $GLOBALS['db']->lastInsertId();
}
require $root . '/include/global_constants.php';
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('REPORT_PERSISTENCE_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/lib/auth.php';
require $root . '/lib/reports.php';
require $root . '/lib/html_reports.php';
// Use the production form metadata for report columns, including the fields
// duplicate_reports copies. All rows are persisted, not canned return values.
$columns = array('id INTEGER PRIMARY KEY AUTOINCREMENT', 'user_id INTEGER');
foreach ($fields_reports_edit as $field => $descriptor) {
    if (!str_starts_with($descriptor['method'], 'hidden') && !str_starts_with($descriptor['method'], 'spacer')) {
        $columns[] = $field . " TEXT DEFAULT ''";
    }
}
$db->exec('CREATE TABLE reports (' . implode(', ', $columns) . ')');
$db->exec("INSERT INTO reports (id, user_id, name, enabled) VALUES (7, 42, 'Weekly network', 'on'), (8, 99, 'Other owner', 'on')");
$result = null;
$operation = $scenario['operation'];
switch ($operation) {
    case 'add-device':
        $result = reports_add_devices(7, $scenario['devices'] ?? array(100), 4, 2);
        break;
    case 'duplicate-device':
        reports_add_devices(7, array(100), 4, 2);
        $result = reports_add_devices(7, array(100), 4, 2);
        break;
    case 'add-graph':
        $result = reports_add_graphs(7, 200, 4, 2);
        break;
    case 'duplicate-graph':
        reports_add_graphs(7, 200, 4, 2);
        $result = reports_add_graphs(7, 200, 4, 2);
        break;
    case 'copy-report':
        duplicate_reports(7, 'Copy of <name>');
        break;
    case 'reorder':
        reports_item_dnd();
        break;
    case 'remove':
        $request['item_id'] = $scenario['item'] ?? 70;
        reports_item_remove();
        break;
    default:
        throw new InvalidArgumentException('Unknown fixture operation.');
}
print json_encode(array('result' => $result, 'messages' => $messages, 'reports' => db_fetch_assoc_prepared('SELECT id, user_id, name, enabled FROM reports ORDER BY id'), 'items' => db_fetch_assoc_prepared('SELECT * FROM reports_items ORDER BY id')), JSON_THROW_ON_ERROR);
