<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Exercise the real utility after unrelated bootstrap/routing boundaries.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
$db = new PDO(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$suffix = bin2hex(random_bytes(8));
$tables = ['user_auth' => 'utility_auth_' . $suffix, 'user_log' => 'utility_log_' . $suffix];
$calls = [];
function utility_statement($sql, $parameters = [])
{
    foreach ($GLOBALS['tables'] as $logical => $physical) {
        $sql = preg_replace('/\b' . $logical . '\b/', '`' . $physical . '`', $sql);
    }
    $GLOBALS['calls'][] = $sql;
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($parameters);
    return $statement;
}
function db_fetch_assoc($sql)
{
    return utility_statement($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $parameters)
{
    return utility_statement($sql, $parameters)->fetchColumn();
}
function db_execute_prepared($sql, $parameters)
{
    utility_statement($sql, $parameters);
    return true;
}
function db_execute($sql)
{
    utility_statement($sql);
    return true;
}
function cacti_sizeof($value)
{
    return count($value);
}
function set_default_action() {}
function get_request_var($name)
{
    return 'native_fixture';
}
function api_plugin_hook_function($name, $value)
{
    return true;
}
try {
    $db->exec('CREATE TABLE `' . $tables['user_auth'] . '` (id INTEGER PRIMARY KEY, username VARCHAR(100), realm INTEGER, UNIQUE (realm, username)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE `' . $tables['user_log'] . '` (id INTEGER PRIMARY KEY, user_id INTEGER, username VARCHAR(100), result INTEGER, time DATETIME) ENGINE=InnoDB');
    $insertUser = $db->prepare('INSERT INTO `' . $tables['user_auth'] . '` VALUES (?, ?, ?)');
    $insertLog = $db->prepare('INSERT INTO `' . $tables['user_log'] . '` VALUES (?, ?, ?, ?, ?)');
    $rowId = 0;
    $identity = $scenario['identity'] ?? 'distinct';
    $principals = $identity === 'same-name' ? [1 => 'shared principal', 2 => 'shared principal'] : ($identity === 'mismatched-pair' ? [1 => 'bob', 2 => 'sam'] : [1 => "quote' principal", 2 => 'other principal']);
    foreach ($principals as $userId => $username) {
        if ($scenario['current']) {
            $insertUser->execute([$userId, $username, $userId === 1 ? 0 : 2]);
        }
        foreach ([0, 1, 2] as $result) {
            foreach (range(1, $scenario['rows']) as $ordinal) {
                // Insert newest first; deletion must use time, not insertion order.
                $insertLog->execute([++$rowId, $userId, $username, $result, sprintf('2026-09-%02d 12:00:00', $scenario['rows'] - $ordinal + 1)]);
            }
        }
    }
    $insertLog->execute([++$rowId, 99, 'removed principal', 1, '2026-09-30 12:00:00']);
    $insertLog->execute([++$rowId, 1, 'renamed principal', 2, '2026-09-30 12:00:00']);
    if ($identity === 'mismatched-pair') {
        // Both components exist, but belong to different current accounts.
        // Password-change logs are outside login/token retention, isolating orphan cleanup.
        $insertLog->execute([++$rowId, 1, 'sam', 3, '2026-09-30 12:00:00']);
    }
    if (isset($argv[3])) {
        define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
        define('UTILITY_LOG_TEST_COVERAGE', true);
        require __DIR__ . '/rrd-process-coverage.php';
    }
    chdir($directory);
    require $root . '/utilities.php';
    utilities_clear_user_log();
    $result = $db->query('SELECT * FROM `' . $tables['user_log'] . '` ORDER BY user_id, result')->fetchAll(PDO::FETCH_ASSOC);
    define('NATIVE_COVERAGE_COMPLETED', ['retained-history-readback']);
    echo json_encode(['rows' => $result, 'queries' => $calls], JSON_THROW_ON_ERROR);
} finally {
    foreach ($tables as $table) {
        $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
}
