<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace PollerReplicationTableDatabase;

use PDO;
use PDOStatement;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

$source = file_get_contents(dirname(__DIR__, 2) . '/lib/poller.php');
if ($source === false) {
    throw new \RuntimeException('Cannot read production replication helpers.');
}
foreach (['replicate_out_table', 'replicate_table_to_poller'] as $name) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $name));
}
foreach (['POLLER_VERBOSITY_NONE' => 0, 'POLLER_VERBOSITY_MEDIUM' => 3] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

function statement(string $sql, PDO|false $connection): PDOStatement|false
{
    $state = &$GLOBALS['table_replication'];
    $db = $connection ?: $state['source'];
    $kind = match (true) {
        str_starts_with($sql, 'SHOW GLOBAL') => 'packet',
        str_starts_with($sql, 'SHOW CREATE') => 'schema',
        str_starts_with($sql, 'SHOW COLUMNS') => $connection ? 'remote-columns' : 'local-columns',
        str_starts_with($sql, 'SELECT COUNT') => 'count',
        str_starts_with($sql, 'DROP') => 'drop',
        str_starts_with($sql, 'CREATE') => 'create',
        str_starts_with($sql, 'TRUNCATE') => 'truncate',
        default => 'insert',
    };
    $state['queries'][] = $kind;
    if ($kind === 'insert') {
        $state['inserts']++;
    }
    if ($state['failed_at'] === null && ($state['failure'] === $kind || ($state['failure'] === 'late-insert' && $kind === 'insert' && $state['inserts'] === 2))) {
        $state['failed_at'] = count($state['queries']);
        // Exercise the real silent PDO error outcome instead of manufacturing a successful statement.
        $sql = 'SELECT * FROM kadupul_760_missing_table_' . $state['suffix'];
    }
    $statement = $db->prepare($sql);
    if ($statement === false || !$statement->execute()) {
        return false;
    }
    $state['affected'] = $statement->rowCount();

    return $statement;
}

function db_fetch_row(string $sql, bool $log = true, PDO|false $connection = false): array|false
{
    $query = statement($sql, $connection);

    return $query === false ? false : ($query->fetch(PDO::FETCH_ASSOC) ?: []);
}

function db_fetch_assoc(string $sql, bool $log = true, PDO|false $connection = false): array|false
{
    $query = statement($sql, $connection);

    return $query === false ? false : $query->fetchAll(PDO::FETCH_ASSOC);
}

function db_fetch_cell(string $sql, string $column = '', bool $log = true, PDO|false $connection = false): mixed
{
    $query = statement($sql, $connection);

    return $query === false ? false : $query->fetchColumn();
}

function db_execute(string $sql, bool $log = true, PDO|false $connection = false): bool
{
    return statement($sql, $connection) !== false;
}

function db_table_exists(string $table, bool $log = true, PDO|false $connection = false): bool
{
    $db = $connection ?: $GLOBALS['table_replication']['source'];

    return $db->query('SHOW CREATE TABLE `' . $table . '`') !== false;
}

function db_column_exists(string $table, string $column, bool $log = true, PDO|false $connection = false): bool
{
    $columns = db_fetch_assoc('SHOW COLUMNS FROM ' . $table, $log, $connection);

    return is_array($columns) && in_array($column, array_column($columns, 'Field'), true);
}

function db_qstr(mixed $value): string
{
    return $GLOBALS['table_replication']['source']->quote((string) $value);
}
function db_affected_rows(PDO $connection): int
{
    return $GLOBALS['table_replication']['affected'];
}
function cacti_sizeof(mixed $rows): int
{
    return is_countable($rows) ? count($rows) : 0;
}
function array_rekey(array $rows, string $key, string $value): array
{
    return array_column($rows, $value, $key);
}
function cacti_log(string $message, mixed ...$args): void
{
    $GLOBALS['table_replication']['logs'][] = $message;
}
function replicate_log(string $message, mixed ...$args): void
{
    cacti_log($message);
}

test('replication helpers propagate real database failures and retain success contracts', function (string $mode, string $failure, string $shape) {
    $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
    if (!$dsn) {
        $this->markTestSkipped('A disposable MySQL-compatible database is required.');
    }
    $connect = static fn(): PDO => new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $primary = $connect();
    $remote = $connect();
    $schema = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
    expect(preg_match('/^CREATE TABLE data_input \(.*?^\)[^;]*;/ms', $schema, $match))->toBe(1);
    $ddl = str_replace('CREATE TABLE data_input (', 'CREATE TEMPORARY TABLE data_input (', $match[0]);
    $GLOBALS['table_replication'] = ['source' => $primary, 'failure' => $failure, 'suffix' => bin2hex(random_bytes(6)), 'queries' => [], 'inserts' => 0, 'affected' => 0, 'logs' => [], 'failed_at' => null];
    try {
        expect($primary->exec($ddl))->not->toBeFalse();
        if ($shape !== 'missing') {
            expect($remote->exec($shape === 'different' ? 'CREATE TEMPORARY TABLE data_input (id INT PRIMARY KEY)' : $ddl))->not->toBeFalse();
            if ($shape !== 'empty-target') {
                $oldName = $shape === 'unchanged' ? 'replacement' : 'previous';
                expect($remote->exec($shape === 'different' ? 'INSERT INTO data_input VALUES (1)' : "INSERT INTO data_input (id,name,input_string) VALUES (1,'$oldName','')"))->not->toBeFalse();
            }
        }
        if ($shape === 'case-columns') {
            expect($remote->exec("ALTER TABLE data_input CHANGE name NAME VARCHAR(200) NOT NULL DEFAULT ''"))->not->toBeFalse();
        }
        if (!in_array($shape, ['empty', 'invalid'], true)) {
            if ($failure === 'late-insert') {
                $values = array_map(static fn(int $id): string => "($id,'row-$id','')", range(1, 1002));
                expect($primary->exec('INSERT INTO data_input (id,name,input_string) VALUES ' . implode(',', $values)))->not->toBeFalse();
            } else {
                expect($primary->exec("INSERT INTO data_input (id,name,input_string) VALUES (1,'replacement','')"))->not->toBeFalse();
            }
        }
        // Match the SELECT * producer shape, including defaults and nullable columns.
        $data = $shape === 'invalid' ? false : $primary->query('SELECT * FROM data_input ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $exclude = in_array($shape, ['excluded', 'empty-target'], true) ? ['name'] : false;
        $result = $mode === 'upsert' ? replicate_table_to_poller($remote, $data, 'data_input', $exclude) : replicate_out_table($remote, $data, 'data_input', 2, $mode !== 'update', $exclude);
        $state = $GLOBALS['table_replication'];
        $success = $failure === '' && $shape !== 'invalid';
        expect($result)->toBe($success);
        if ($shape === 'invalid') {
            expect($state['queries'])->toBe([]);
        }
        if ($shape === 'unchanged' && $mode === 'upsert') {
            expect($state['affected'])->toBe(0);
        }
        if ($state['failed_at'] !== null) {
            expect(count($state['queries']))->toBe($state['failed_at']);
        }
        expect(str_contains(implode('\n', $state['logs']), ' With '))->toBe($success && $shape !== 'empty');
        $rows = $remote->query('SELECT * FROM data_input ORDER BY id');
        $rows = $rows === false ? null : array_map(static fn(array $row): array => array_change_key_case($row), $rows->fetchAll(PDO::FETCH_ASSOC));
        if ($success) {
            expect($rows)->toHaveCount($shape === 'empty' && $mode === 'bulk' ? 0 : 1);
            if ($shape !== 'empty') {
                expect($rows[0]['name'])->toBe($shape === 'excluded' ? 'previous' : 'replacement');
            }
        } elseif ($failure === 'late-insert') {
            // The first batch is already committed; #761 tracks atomic replacement separately.
            expect($rows)->toHaveCount(1001)->and($rows[0]['name'])->toBe('row-1');
        } elseif ($failure === 'insert' && $mode === 'bulk') {
            expect($rows)->toBe([]);
        } elseif ($shape === 'missing' || ($shape === 'different' && $failure === 'create')) {
            expect($rows)->toBeNull();
        } else {
            expect($rows)->toHaveCount(1);
            if ($shape !== 'different') {
                expect($rows[0]['name'])->toBe('previous');
            }
        }
    } finally {
        foreach ([$primary, $remote] as $connection) {
            $connection->exec('DROP TEMPORARY TABLE IF EXISTS data_input');
        }
        unset($GLOBALS['table_replication']);
    }
})->with([
    'bulk column-name case' => ['bulk', '', 'case-columns'],
    'upsert column-name case' => ['upsert', '', 'case-columns'],
    'bulk success' => ['bulk', '', 'normal'],
    'bulk empty' => ['bulk', '', 'empty'],
    'bulk missing' => ['bulk', '', 'missing'],
    'bulk schema replacement' => ['bulk', '', 'different'],
    'bulk failed source load' => ['bulk', '', 'invalid'],
    'bulk schema read' => ['bulk', 'schema', 'normal'],
    'bulk local columns' => ['bulk', 'local-columns', 'normal'],
    'bulk remote columns' => ['bulk', 'remote-columns', 'normal'],
    'bulk remote count' => ['bulk', 'count', 'normal'],
    'bulk first create' => ['bulk', 'create', 'missing'],
    'bulk drop' => ['bulk', 'drop', 'different'],
    'bulk recreate' => ['bulk', 'create', 'different'],
    'bulk truncate' => ['bulk', 'truncate', 'normal'],
    'bulk empty truncate' => ['bulk', 'truncate', 'empty'],
    'bulk insert' => ['bulk', 'insert', 'normal'],
    'bulk later batch' => ['bulk', 'late-insert', 'normal'],
    'bulk update exclusions' => ['update', '', 'excluded'],
    'upsert exclusions' => ['upsert', '', 'excluded'],
    'upsert empty target retains incoming fields' => ['upsert', '', 'empty-target'],
    'upsert unchanged' => ['upsert', '', 'unchanged'],
    'upsert success' => ['upsert', '', 'normal'],
    'upsert empty' => ['upsert', '', 'empty'],
    'upsert failed source load' => ['upsert', '', 'invalid'],
    'upsert packet read' => ['upsert', 'packet', 'normal'],
    'upsert columns' => ['upsert', 'remote-columns', 'normal'],
    'upsert remote count' => ['upsert', 'count', 'normal'],
    'upsert insert' => ['upsert', 'insert', 'normal'],
]);
