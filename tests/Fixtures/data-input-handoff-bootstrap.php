<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$fixtureRoot = json_decode(file_get_contents(dirname(__DIR__) . '/source.json'), true);
require $fixtureRoot . '/include/vendor/autoload.php';
class DataInputHandoffFixtureDatabase extends PDO
{
    public function exec(string $statement): int|false
    {
        return str_starts_with($statement, 'SET TRANSACTION ') ? 0 : parent::exec($statement);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'information_schema.TABLES')) {
            $query = "SELECT 'INNODB' AS ENGINE WHERE ? IS NOT NULL";
        }
        return parent::prepare(str_replace([' LOCK IN SHARE MODE', ' FOR UPDATE'], '', $query), $options);
    }
}
$db = new DataInputHandoffFixtureDatabase('sqlite:' . dirname(__DIR__) . '/worker.sqlite');
$database_hostname = 'fixture';
$database_port = '0';
$database_default = 'fixture';
$database_sessions = ['fixture:0:fixture' => $db];
$config = ['poller_id' => file_get_contents(dirname(__DIR__) . '/mode') === 'collector' ? 2 : 1, 'base_path' => dirname(__DIR__), 'input_whitelist' => dirname(__DIR__) . '/whitelist'];
$_SESSION = [];
define('MESSAGE_LEVEL_WARN', 2);
function read_config_option($name)
{
    return PHP_BINARY;
}
function update_replication_crc($unused, $name)
{
    global $db;
    $statement = $db->prepare('INSERT INTO settings(name,value) VALUES(?,1) ON CONFLICT(name) DO UPDATE SET value=CAST(value AS INTEGER)+1');
    $statement->execute([$name]);
}
function push_out_data_input_method($id)
{
    file_put_contents(dirname(__DIR__) . '/collector-' . $id, 'started');
    $mode = file_get_contents(dirname(__DIR__) . '/mode');
    if ($mode === 'warning') {
        $_SESSION['sess_messages'][] = ['level' => MESSAGE_LEVEL_WARN];
    }
}
function db_error()
{
    return file_get_contents(dirname(__DIR__) . '/mode') === 'db_error' ? 'Simulated collector failure' : '';
}
function is_error_message()
{
    return file_get_contents(dirname(__DIR__) . '/mode') === 'message_error';
}
function verify_data_input_whitelist($hash, $command)
{
    global $config;
    return is_file($config['input_whitelist']);
}
function cacti_log(...$arguments) {}
// The fail-before worker executes the unchanged real legacy launcher.
require $fixtureRoot . '/tests/Helpers/PhpSource.php';
eval(test_php_function_source(file_get_contents($fixtureRoot . '/lib/functions.php'), 'cacti_exec'));
