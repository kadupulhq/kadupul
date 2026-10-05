<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

require dirname(__DIR__) . '/Helpers/PhpSource.php';
require dirname(__DIR__, 2) . '/include/global_constants.php';
define('CACTI_VERSION', '1.2.31');
$config = ['base_path' => getenv('PLUGIN_NATIVE_ROOT'), 'url_path' => '/cacti/', 'poller_id' => 1];
$database = new PDO('sqlite:' . $config['base_path'] . '/database.sqlite');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('CREATE TABLE IF NOT EXISTS plugin_config (id INTEGER PRIMARY KEY, directory TEXT UNIQUE, name TEXT, author TEXT, webpage TEXT, version TEXT, status INTEGER DEFAULT 0)');
$database->exec('CREATE TABLE IF NOT EXISTS plugin_realms (id INTEGER PRIMARY KEY, plugin TEXT, file TEXT, display TEXT, UNIQUE(plugin,file))');
$database->exec('CREATE TABLE IF NOT EXISTS user_auth (id INTEGER PRIMARY KEY)');
$database->exec('INSERT OR IGNORE INTO user_auth VALUES (7)');
$database->exec('CREATE TABLE IF NOT EXISTS user_auth_realm (user_id INTEGER, realm_id INTEGER, PRIMARY KEY(user_id,realm_id))');
function native_plugin_query($sql, $parameters = [])
{
    global $database;
    $statement = $database->prepare($sql);
    $statement->execute($parameters);
    return $statement;
}
function db_fetch_row_prepared($sql, $parameters = [])
{
    return native_plugin_query($sql, $parameters)->fetch(PDO::FETCH_ASSOC) ?: [];
}
function db_fetch_assoc_prepared($sql, $parameters = [])
{
    return native_plugin_query($sql, $parameters)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $parameters = [])
{
    return native_plugin_query($sql, $parameters)->fetchColumn();
}
function db_execute_prepared($sql, $parameters = [])
{
    native_plugin_query($sql, $parameters);
    return true;
}
// There are no remote collectors in this isolated fixture.
function db_fetch_assoc($sql)
{
    return [];
}
function array_rekey($rows, ...$arguments)
{
    return $rows;
}
function read_config_option($name)
{
    return $name === 'admin_user' ? '7' : 300;
}
function __($message, ...$arguments)
{
    return $arguments ? vsprintf($message, $arguments) : $message;
}
function __esc($message, ...$arguments)
{
    return html_escape(__($message, ...$arguments));
}
function html_escape($value)
{
    return htmlspecialchars($value, ENT_QUOTES);
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log(...$arguments)
{
}
function cacti_version_compare($left, $right, $operator)
{
    return version_compare($left, $right, $operator);
}
function raise_message(...$arguments)
{
    $_SESSION['messages'][] = $arguments;
}
$source = file_get_contents(dirname(__DIR__, 2).'/lib/functions.php');
if ($source === false) {
    throw new RuntimeException('Cannot load plugin path helpers');
}
foreach (['cacti_path_is_within','validate_relative_path_within','cacti_plugin_path'] as $function) {
    eval(test_php_function_source($source, $function));
}
require dirname(__DIR__, 2).'/lib/plugins.php';
if (getenv('PLUGIN_NATIVE_MODE') === 'ui') {
    $source = file_get_contents(dirname(__DIR__, 2).'/plugins.php');
    if ($source === false) {
        throw new RuntimeException('Cannot load plugin UI');
    }
    foreach (['plugin_required_installed','plugin_actions'] as $function) {
        eval(test_php_function_source($source, $function));
    }
    $row = ['directory' => 'native_fixture','infoname' => 'Native_fixture','status' => '0'];
    $info = plugin_load_info_file($config['base_path'].'/plugins/native_fixture/INFO');
    echo json_encode(['html' => plugin_actions($row,[]),'info' => $info], JSON_THROW_ON_ERROR);
}
