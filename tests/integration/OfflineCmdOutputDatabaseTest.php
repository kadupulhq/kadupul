<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace OfflineCmdOutputDatabase;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

$source = file_get_contents(dirname(__DIR__, 2) . '/cmd.php');
if ($source === false) {
    throw new \RuntimeException('Cannot read the production collector');
}
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, 'cmd_write_poller_output'));

function read_config_option(string $name): string
{
    return 'on';
}

function cacti_log(string $message, ...$args): void
{
    $GLOBALS['offline_cmd_logs'][] = $message;
}

function db_execute(string $sql, bool $log, mixed $connection): bool
{
    if ($connection !== $GLOBALS['offline_cmd_db']) {
        throw new \RuntimeException('Collector used the wrong database connection');
    }
    $stage = match (true) {
        str_starts_with($sql, 'SET timestamp = UNIX_TIMESTAMP') => 'pin',
        str_starts_with($sql, 'SET timestamp = DEFAULT') => 'reset',
        str_starts_with($sql, 'INSERT IGNORE INTO poller_output_boost') => 'boost',
        default => 'primary',
    };
    $GLOBALS['offline_cmd_stages'][] = $stage;
    if ($stage === $GLOBALS['offline_cmd_failure']) {
        // Real silent-driver failures exercise the helper's false-return contract.
        return $connection->exec('SET kadupul_unavailable_session_variable = 1') !== false;
    }
    if ($stage === 'boost') {
        // Cross a server-clock second between the two CURRENT_TIMESTAMP() evaluations.
        $connection->query('SELECT SLEEP(1.1)')->fetchColumn();
    }
    return $connection->exec($sql) !== false;
}

test('collector queue copies share their timestamp and report every write stage failure', function (string $failure) {
    $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
    expect($dsn)->not->toBeFalse();
    $db = new \PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);
    expect($db->exec("SET time_zone = '+00:00'"))->not->toBeFalse();
    foreach (['poller_output', 'poller_output_boost'] as $table) {
        expect($db->exec("CREATE TEMPORARY TABLE $table (local_data_id INT UNSIGNED NOT NULL, rrd_name VARCHAR(19) NOT NULL, time TIMESTAMP NOT NULL, output VARCHAR(512) NOT NULL, PRIMARY KEY(local_data_id,rrd_name,time)) ENGINE=InnoDB"))->not->toBeFalse();
    }
    $GLOBALS['poller_db_cnn_id'] = $GLOBALS['offline_cmd_db'] = $db;
    $GLOBALS['offline_cmd_failure'] = $failure;
    $GLOBALS['offline_cmd_logs'] = $GLOBALS['offline_cmd_stages'] = [];
    try {
        expect(cmd_write_poller_output(["(1, 'value', CURRENT_TIMESTAMP(), 'sample')"]))->toBe($failure === '');
        $primary = $db->query('SELECT * FROM poller_output')->fetchAll(\PDO::FETCH_ASSOC);
        $boost = $db->query('SELECT * FROM poller_output_boost')->fetchAll(\PDO::FETCH_ASSOC);
        expect($primary)->toHaveCount(in_array($failure, ['pin', 'primary'], true) ? 0 : 1)
            ->and($boost)->toHaveCount(in_array($failure, ['pin', 'boost'], true) ? 0 : 1);
        if ($primary && $boost) {
            expect($primary)->toBe($boost);
        }
        expect($GLOBALS['offline_cmd_logs'])->toHaveCount($failure === '' ? 0 : 1)
            ->and($GLOBALS['offline_cmd_stages'])->toBe($failure === 'pin' ? ['pin'] : ['pin', 'primary', 'boost', 'reset']);
        if (!in_array($failure, ['pin', 'reset'], true)) {
            $before = (int) $db->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
            $db->query('SELECT SLEEP(1.1)')->fetchColumn();
            expect((int) $db->query('SELECT UNIX_TIMESTAMP()')->fetchColumn())->toBeGreaterThan($before);
        }
    } finally {
        $db->exec('SET timestamp = DEFAULT');
        unset($GLOBALS['poller_db_cnn_id'], $GLOBALS['offline_cmd_db']);
    }
})->with(['', 'pin', 'primary', 'boost', 'reset']);
