<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$scenario = json_decode($argv[1], true);
$pdo = new PDO($scenario['dsn'], $scenario['user'], $scenario['password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$prefix = $scenario['prefix'];
$database_hostname = 'native';
$database_port = 0;
$database_default = 'auth';
$database_sessions = array('native:0:auth' => $pdo);
$_SESSION = array('sess_user_id' => 1);
function group_probe_sql($sql)
{
    return preg_replace('/\b(user_auth(?:_group_members|_group_realm|_group_perms|_group)?)\b/', $GLOBALS['prefix'] . '_$1', $sql);
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
function __($text, ...$args)
{
    return vsprintf($text, $args);
}
function cacti_require_post_actions($actions) {}
function isset_request_var($name)
{
    return false;
}
function set_default_action() {}
function get_request_var($name)
{
    return 'native-test';
}
function api_plugin_hook_function($name, $value = null)
{
    return true;
}
function kill_session_var($name)
{
    unset($_SESSION[$name]);
}
$root = dirname(__DIR__, 2);
require $root . '/lib/auth.php';
$directory = sys_get_temp_dir() . '/group-native-' . bin2hex(random_bytes(8));
mkdir($directory . '/include', 0700, true);
file_put_contents($directory . '/include/auth.php', '<?php');
chdir($directory);
require $root . '/user_group_admin.php';
register_shutdown_function(function () use ($directory) {
    unlink($directory . '/include/auth.php');
    rmdir($directory . '/include');
    rmdir($directory);
});
print "READY\n";
flush();
if ($scenario['action'] === 'remove') {
    user_group_remove(5);
} else {
    user_group_update_membership(5, 44, true);
}
$q = $pdo->query('SELECT id FROM ' . $prefix . '_user_auth WHERE reset_perms > 0 ORDER BY id');
print json_encode(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN))) . "\n";
