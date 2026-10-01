<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require __DIR__ . '/PhpSource.php';
$scenario = json_decode($argv[1], true);
$pdo = new PDO($scenario['dsn'], $scenario['user'], $scenario['password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$prefix = $scenario['prefix'];
$resets = array();
function group_probe_sql($sql)
{
    return preg_replace('/\b(user_auth_group(?:_members|_realm|_perms)?)\b/', $GLOBALS['prefix'] . '_$1', $sql);
}
function db_begin_transaction()
{
    return $GLOBALS['pdo']->beginTransaction();
}
function db_commit_transaction()
{
    return $GLOBALS['pdo']->commit();
}
function db_rollback_transaction()
{
    return $GLOBALS['pdo']->rollBack();
}
function db_fetch_cell_prepared($sql, $params)
{
    print "LOCK\n";
    flush();
    $query = $GLOBALS['pdo']->prepare(group_probe_sql($sql));
    $query->execute($params);
    return $query->fetchColumn();
}
function db_fetch_assoc_prepared($sql, $params)
{
    $query = $GLOBALS['pdo']->prepare(group_probe_sql($sql));
    $query->execute($params);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    print "SNAPSHOT\n";
    flush();
    return $rows;
}
function db_execute_prepared($sql, $params)
{
    $query = $GLOBALS['pdo']->prepare(group_probe_sql($sql));
    return $query->execute($params);
}
function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}
function reset_user_perms($id)
{
    $GLOBALS['resets'][] = (int) $id;
}
$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/' . ($scenario['action'] === 'remove' ? 'user_group_admin.php' : 'lib/auth.php'));
$function = $scenario['action'] === 'remove' ? 'user_group_remove' : 'user_group_update_membership';
eval(test_php_function_source($source, $function));
print "READY\n";
flush();
if ($scenario['action'] === 'remove') {
    user_group_remove(5);
} else {
    user_group_update_membership(5, 44, true);
}
print json_encode($resets) . "\n";
