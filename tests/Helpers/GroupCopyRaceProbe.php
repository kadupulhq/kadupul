<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require __DIR__ . '/PhpSource.php';
$scenario = json_decode($argv[1], true);
$db = new PDO($scenario['dsn'], $scenario['user'], $scenario['password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
function copy_race_sql($sql)
{
    return preg_replace('/\b(user_auth_group(?:_realm|_perms)?)\b/', $GLOBALS['scenario']['prefix'] . '_$1', $sql);
}
function db_execute_prepared($sql, $params)
{
    $q = $GLOBALS['db']->prepare(copy_race_sql($sql));
    return $q->execute($params);
}
function db_fetch_insert_id()
{
    return $GLOBALS['db']->lastInsertId();
}
function db_qstr($value)
{
    return $GLOBALS['db']->quote($value);
}
function cacti_sizeof($value)
{
    return count($value);
}
function db_fetch_assoc_prepared($sql, $params)
{
    if (!isset($GLOBALS['paused'])) {
        $GLOBALS['paused'] = true;
        echo 'COPIED:' . db_fetch_insert_id() . "\n";
        flush();
        fgets(STDIN);
    }
    $q = $GLOBALS['db']->prepare(copy_race_sql($sql));
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $params)
{
    $q = $GLOBALS['db']->prepare(copy_race_sql($sql));
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
$root = dirname(__DIR__, 2);
eval(test_php_function_source(file_get_contents($root . '/lib/auth.php'), 'user_group_execute_child'));
eval(test_php_function_source(file_get_contents($root . '/user_group_admin.php'), 'user_group_copy'));
try {
    user_group_copy(5);
    echo 'COPIED_GRANTS';
} catch (RuntimeException $error) {
    echo 'REFUSED_REMOVED_PARENT';
}
