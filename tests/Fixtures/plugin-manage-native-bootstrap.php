<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$scenario = json_decode(getenv('PLUGIN_NATIVE_SCENARIO'), true, 512, JSON_THROW_ON_ERROR);
$config = array('base_path' => getenv('PLUGIN_NATIVE_DIRECTORY'));
$calls = array();
$installed = array();
if (!empty($scenario['mysql'])) {
    $host = getenv('BOOST_DB_HOST') ?: '127.0.0.1';
    $port = getenv('BOOST_DB_PORT') ?: '3306';
    $database = getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract';
    $socket = getenv('BOOST_DB_SOCKET');
    $dsn = $socket ? "mysql:unix_socket=$socket;dbname=$database;charset=utf8mb4" : "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
    $db = new PDO($dsn, getenv('BOOST_DB_USER') ?: 'root', getenv('BOOST_DB_PASSWORD') ?: '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $schema = file_get_contents(getenv('PLUGIN_NATIVE_ROOT') . '/cacti.sql');
    foreach (array('user_auth', 'plugin_realms', 'user_auth_realm') as $table) {
        if (!preg_match('/CREATE TABLE `?' . $table . '`? \(.*?\) ENGINE=.*?;/s', $schema, $match)) {
            throw new RuntimeException('Production schema table missing: ' . $table);
        }
        // Connection-local copies of the exact production schema; no shared tables change.
        $db->exec(str_replace('CREATE TABLE ', 'CREATE TEMPORARY TABLE ', $match[0]));
    }
} else {
    $db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $db->exec('CREATE TABLE user_auth(id INTEGER PRIMARY KEY);
        CREATE TABLE plugin_realms(id INTEGER PRIMARY KEY,plugin TEXT,file TEXT,display TEXT);
        CREATE TABLE user_auth_realm(realm_id INTEGER,user_id INTEGER,PRIMARY KEY(realm_id,user_id));');
}
$db->exec("INSERT INTO user_auth(id) VALUES (1),(7),(8);
    INSERT INTO plugin_realms(id,plugin,file,display) VALUES (12,'fixture','a.php','A'),(13,'fixture','b.php','B'),(14,'other','c.php','C'),(15,'second','d.php','D');
    INSERT INTO user_auth_realm(realm_id,user_id) VALUES (112,7),(114,8);");
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function read_config_option($name)
{
    return $GLOBALS['scenario']['admin'] ?? '7';
}
function api_plugin_installed($plugin)
{
    return empty($GLOBALS['scenario']['fresh']) || !empty($GLOBALS['installed'][$plugin]);
}
function api_plugin_can_install($plugin, &$message)
{
    return true;
}
function api_plugin_install($plugin)
{
    $GLOBALS['installed'][$plugin] = true;
    $GLOBALS['calls'][] = array('install', $plugin);
}
function plugin_native_query($sql, $params)
{
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function db_fetch_row_prepared($sql, $params)
{
    return plugin_native_query($sql, $params)->fetch(PDO::FETCH_ASSOC);
}
function db_fetch_assoc_prepared($sql, $params)
{
    return plugin_native_query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $params)
{
    if (!empty($GLOBALS['scenario']['verify_fail'])) {
        return false;
    }
    return plugin_native_query($sql, $params)->fetchColumn();
}
function db_execute_prepared($sql, $params)
{
    $GLOBALS['calls'][] = array('grant', (int) $params[0], (int) $params[1]);
    if (!empty($GLOBALS['scenario']['grant_fail']) || (!empty($GLOBALS['scenario']['first_fail']) && $params[1] != 115)) {
        return false;
    }
    if (!empty($GLOBALS['scenario']['verify_fail'])) {
        return true;
    }
    plugin_native_query($sql, $params);
    return true;
}
register_shutdown_function(function () {
    echo "\nRESULT:" . json_encode(array('calls' => $GLOBALS['calls'], 'grants' => $GLOBALS['db']->query('SELECT user_id,realm_id FROM user_auth_realm ORDER BY user_id,realm_id')->fetchAll(PDO::FETCH_ASSOC)), JSON_THROW_ON_ERROR);
});
