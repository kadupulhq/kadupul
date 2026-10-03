<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Only the collector transport/bootstrap is isolated. Authorization, state SQL,
// revision comparison and transaction ownership execute the full real leaf.
$fixtureRoot = json_decode(file_get_contents(dirname(__DIR__) . '/source.json'), true);
require $fixtureRoot . '/include/vendor/autoload.php';
$dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
$dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . file_get_contents(dirname(__DIR__) . '/database'), $dsn);
$connect = static fn() => new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = $connect();
$database_hostname = 'fixture';
$database_port = '0';
$database_default = 'fixture';
$database_sessions = ['fixture:0:fixture' => $db];
$config = ['poller_id' => 1, 'base_path' => dirname(__DIR__), 'input_whitelist' => dirname(__DIR__) . '/whitelist'];
$_SESSION = [];
define('MESSAGE_LEVEL_WARN', 2);
function push_out_data_input_method($id)
{
    global $db, $connect;
    $writer = $connect();
    $writer->exec('SET SESSION innodb_lock_wait_timeout=1');
    $writer->exec("UPDATE data_input SET name='Unrelated writer' WHERE id=4");
    file_put_contents(dirname(__DIR__) . '/started', 'started');
    if (file_get_contents(dirname(__DIR__) . '/mode') === 'slow') {
        usleep(3000000);
        file_put_contents(dirname(__DIR__) . '/late', 'late');
        return;
    }
    $blocked = false;
    try {
        $writer->exec("UPDATE data_input SET input_string='Concurrent edit' WHERE id=3");
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) !== 1205) {
            throw $error;
        }
        $blocked = true;
    }
    file_put_contents(dirname(__DIR__) . '/observed', json_encode([
        'owned_transaction' => $db->inTransaction(),
        'target_writer_blocked' => $blocked,
        'command' => $db->query('SELECT input_string FROM data_input WHERE id=3')->fetchColumn(),
    ], JSON_THROW_ON_ERROR));
}
function db_error()
{
    return '';
}
function is_error_message()
{
    return false;
}
