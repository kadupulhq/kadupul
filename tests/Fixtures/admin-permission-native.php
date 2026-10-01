<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Exercise real admin handlers and cache-reset SQL after the separately tested
// authorization/CSRF/bootstrap boundary. Controller functions are never copied.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
chdir($directory);
$group = $scenario['group'];
$target = 42;
$operation = $scenario['operation'];
$request = array('action' => $operation === 'realm' ? 'save' : 'perm_remove', 'id' => $operation === 'realm' ? $target : 100, 'user_id' => $target, 'group_id' => $target, 'type' => $scenario['type'] ?? 'graph');
$_POST = array();
if ($operation === 'realm') {
    $request['save_component_realm_perms'] = '1';
    foreach ($scenario['realms'] as $realm) {
        $_POST['section' . $realm] = 'on';
    }
    $_POST['unrelated_field'] = 'on';
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION = array('sess_user_id' => ($scenario['self'] ?? false) ? $target : 41, 'sess_user_realms' => array(99), 'sess_user_config_array' => array('stale'), 'sess_config_array' => array('stale'), 'sess_auth_names' => array('stale'));
$initial_session = $_SESSION;
$messages = array();
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Native SQL's random reset marker stays a marker rather than a canned UPDATE.
$db->sqliteCreateFunction('RAND', static fn() => random_int(1, 4294967294) / 4294967295);
$db->sqliteCreateFunction('FLOOR', static fn($value) => floor($value));
$db->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, reset_perms INTEGER DEFAULT 0)');
$db->exec('INSERT INTO user_auth (id) VALUES (41), (42), (43), (44)');
$db->exec('CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO user_auth_group VALUES (42),(43)');
$db->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
$db->exec('INSERT INTO user_auth_group_members VALUES (42, 42), (42, 44), (43, 43)');
$db->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER, UNIQUE(user_id, realm_id))');
$db->exec('INSERT INTO user_auth_realm VALUES (42, 7), (43, 9)');
$db->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER, UNIQUE(group_id, realm_id))');
$db->exec('INSERT INTO user_auth_group_realm VALUES (42, 7), (43, 9)');
$db->exec('CREATE TABLE user_auth_perms (user_id INTEGER, item_id INTEGER, type INTEGER, UNIQUE(user_id, item_id, type))');
$db->exec('CREATE TABLE user_auth_group_perms (group_id INTEGER, item_id INTEGER, type INTEGER, UNIQUE(group_id, item_id, type))');
foreach (range(1, 4) as $type) {
    $db->prepare('INSERT INTO user_auth_perms VALUES (42, 100, ?), (42, 101, ?), (43, 100, ?)')->execute(array($type, $type, $type));
    $db->prepare('INSERT INTO user_auth_group_perms VALUES (42, 100, ?), (42, 101, ?), (43, 100, ?)')->execute(array($type, $type, $type));
}
function db_fetch_cell_prepared($sql, $params = array())
{
    $q = $GLOBALS['db']->prepare(str_replace(' FOR UPDATE', '', $sql));
    $q->execute($params);
    return $q->fetchColumn();
}
function db_begin_transaction()
{
    return $GLOBALS['db']->beginTransaction();
}
function db_commit_transaction()
{
    return $GLOBALS['db']->commit();
}
function db_rollback_transaction()
{
    return $GLOBALS['db']->rollBack();
}
function db_execute_prepared($sql, $params = array())
{
    return $GLOBALS['db']->prepare($sql)->execute($params);
}
function db_execute($sql)
{
    return $GLOBALS['db']->exec($sql);
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function array_rekey($rows, $key, $value)
{
    $result = array();
    foreach ($rows as $row) {
        $result[$row[$key]] = $row[$value];
    }
    return $result;
}
function __($text, ...$args)
{
    return $args ? vsprintf($text, $args) : $text;
}
function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}
function get_request_var($name)
{
    return $GLOBALS['request'][$name] ?? '';
}
function get_nfilter_request_var($name, $default = '')
{
    return $GLOBALS['request'][$name] ?? $default;
}
function get_filter_request_var($name)
{
    return (int) get_request_var($name);
}
function isset_request_var($name)
{
    return array_key_exists($name, $GLOBALS['request']);
}
function set_default_action() {}
function is_error_message()
{
    return false;
}
function kill_session_var($name)
{
    unset($_SESSION[$name]);
}
function raise_message($key, ...$args)
{
    $GLOBALS['messages'][] = $key;
}
function cacti_require_post_actions($actions)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Native persistence fixture requires an intentional POST.');
    }
}
require $root . '/include/global_constants.php';
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('ADMIN_PERMISSION_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/lib/auth.php';
ob_start();
register_shutdown_function(static function () use ($db, $group, $initial_session) {
    $output = ob_get_clean();
    $principal = $group ? 'group_id' : 'user_id';
    $realm_table = $group ? 'user_auth_group_realm' : 'user_auth_realm';
    $perm_table = $group ? 'user_auth_group_perms' : 'user_auth_perms';
    print json_encode(array('realms' => $db->query('SELECT * FROM ' . $realm_table . ' ORDER BY ' . $principal . ', realm_id')->fetchAll(PDO::FETCH_ASSOC), 'permissions' => $db->query('SELECT * FROM ' . $perm_table . ' ORDER BY ' . $principal . ', item_id, type')->fetchAll(PDO::FETCH_ASSOC), 'reset' => $db->query('SELECT * FROM user_auth ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'session' => $_SESSION, 'initial_session' => $initial_session, 'messages' => $GLOBALS['messages'], 'output' => $output), JSON_THROW_ON_ERROR);
});
require $root . ($group ? '/user_group_admin.php' : '/user_admin.php');
