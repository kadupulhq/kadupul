<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$directory = $argv[1];
$web = $argv[2] === 'web';
$config = array('base_path' => $directory, 'is_web' => $web, 'poller_id' => 1, 'config_options_array' => array('poller_interval' => 300, 'csrf_secret' => 'old-central'));
$_SESSION = array('sess_config_array' => array('poller_interval' => 300, 'csrf_secret' => 'old-central'));
$central = new PDO('sqlite::memory:', options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$collector = new PDO('sqlite::memory:', options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
foreach (array($central, $collector) as $db) {
    $db->exec('CREATE TABLE settings(name TEXT PRIMARY KEY,value TEXT)');
}
$central->exec("INSERT INTO settings VALUES('csrf_secret','old-central')");
$collector->exec("INSERT INTO settings VALUES('csrf_secret','old-collector')");
$central->exec("CREATE TRIGGER reject_local BEFORE INSERT ON settings BEGIN SELECT RAISE(FAIL,'local settings write refused'); END");
$central->exec('CREATE TABLE poller(id INTEGER,last_status TEXT,disabled TEXT)');
$central->exec("INSERT INTO poller VALUES(2,CURRENT_TIMESTAMP,'')");
$central->sqliteCreateFunction('UNIX_TIMESTAMP', static fn(...$args) => $args ? strtotime($args[0]) : time());
$connections = 0;
function db_execute_prepared($sql, $params, $log = true, $connection = false)
{
    $db = $connection ?: $GLOBALS['central'];
    try {
        return $db->prepare('REPLACE INTO settings(name,value) VALUES(?,?)')->execute($params);
    } catch (PDOException $error) {
        return false;
    }
}
function db_fetch_assoc($sql)
{
    return $GLOBALS['central']->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function poller_connect_to_remote($id)
{
    $GLOBALS['connections']++;
    return $GLOBALS['collector'];
}
require dirname(__DIR__, 2) . '/lib/functions.php';
$result = set_config_option('csrf_secret', 'replacement', true);
echo json_encode(array('result' => $result, 'connections' => $connections, 'central' => $central->query("SELECT value FROM settings WHERE name='csrf_secret'")->fetchColumn(), 'collector' => $collector->query("SELECT value FROM settings WHERE name='csrf_secret'")->fetchColumn(), 'cache' => $web ? $_SESSION['sess_config_array']['csrf_secret'] : $config['config_options_array']['csrf_secret']), JSON_THROW_ON_ERROR);
