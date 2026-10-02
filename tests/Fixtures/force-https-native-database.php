<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec('CREATE TABLE settings(name TEXT, value TEXT); CREATE TABLE version(cacti TEXT); INSERT INTO version VALUES ("1.3.0")');
$statement = $db->prepare('INSERT INTO settings VALUES(?,?)');
foreach (array('force_https' => $scenario['force'] ?? 'on', 'base_url' => $scenario['canonical'] ?? '', 'default_date_format' => '1', 'default_datechar' => '1') as $key => $value) {
    $statement->execute(array($key, $value));
}
function db_connect_real(...$arguments)
{
    $GLOBALS['database_sessions'][$arguments[0] . ':' . $arguments[5] . ':' . $arguments[3]] = new stdClass();
    return true;
}
function db_cacti_initialized(...$arguments) {}
function db_table_exists($table)
{
    return $table === 'settings';
}
function https_native_query($sql, $params)
{
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function db_fetch_cell($sql, ...$arguments)
{
    return https_native_query($sql, array())->fetchColumn();
}
function db_fetch_cell_prepared($sql, $params = array(), ...$arguments)
{
    return https_native_query($sql, $params)->fetchColumn();
}
function db_fetch_row_prepared($sql, $params = array(), ...$arguments)
{
    return https_native_query($sql, $params)->fetch(PDO::FETCH_ASSOC);
}
function db_fetch_assoc_prepared($sql, $params = array(), ...$arguments)
{
    return https_native_query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
function __($message, ...$arguments)
{
    return $arguments ? vsprintf($message, $arguments) : $message;
}
