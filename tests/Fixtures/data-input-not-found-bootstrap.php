<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Real SQLite rows substitute only the worker's external database/bootstrap.
// The copied worker, framed protocol, gateway, and HTTP controller stay native.
require json_decode(file_get_contents(dirname(__DIR__) . '/source.json'), true) . '/include/vendor/autoload.php';
class DataInputNotFoundFixtureDatabase extends PDO
{
    public function exec(string $statement): int|false
    {
        return str_starts_with($statement, 'SET TRANSACTION ') ? 0 : parent::exec($statement);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace([' LOCK IN SHARE MODE', ' FOR UPDATE'], '', $query);
        return parent::prepare($query, $options);
    }
}
$db = new DataInputNotFoundFixtureDatabase('sqlite:' . dirname(__DIR__) . '/worker.sqlite');
$database_hostname = 'fixture';
$database_port = '0';
$database_default = 'fixture';
$database_sessions = ['fixture:0:fixture' => $db];
$config = ['poller_id' => 1];
$_SESSION = [];
function cacti_log(...$arguments)
{
    throw new RuntimeException('Unexpected worker failure');
}
