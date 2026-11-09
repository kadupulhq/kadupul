<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// #761: a full replacement of a collector table must not leave the remote
// table empty, or half written, when a later statement fails.

namespace ReplicateOutTableReplacement;

use PDO;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php');
if ($source === false) {
    throw new \RuntimeException('Cannot read the collector synchronizer.');
}
foreach (['replicate_out_table', 'replicate_out_rollback'] as $name) {
    if (str_contains($source, 'function ' . $name . '(')) {
        eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $name));
    }
}
foreach (['POLLER_VERBOSITY_NONE' => 0, 'POLLER_VERBOSITY_LOW' => 2] as $constant => $value) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

/** A remote whose COMMIT is not acknowledged. */
final class UnacknowledgedCommit extends PDO
{
    public function commit(): bool
    {
        return false;
    }
}

function state(): array
{
    return $GLOBALS['replicate_out_replacement'];
}

function db(mixed $connection): PDO
{
    return $connection instanceof PDO ? $connection : $GLOBALS['replicate_out_replacement']['local'];
}

function db_fetch_row(string $sql, bool $log = true, mixed $connection = false): array|false
{
    if (preg_match('/^SHOW CREATE TABLE `?(\w+)`?$/', $sql, $match) !== 1) {
        return false;
    }
    $ddl = db($connection)->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = " . db($connection)->quote($match[1]))->fetchColumn();
    if ($ddl === false) {
        return false;
    }
    $engine = $connection === false ? 'InnoDB' : state()['engine'];

    return ['Table' => $match[1], 'Create Table' => $ddl . ' ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4'];
}

function db_table_exists(string $table, bool $log = true, mixed $connection = false): bool
{
    return db($connection)->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = " . db($connection)->quote($table))->fetchColumn() !== false;
}

function db_fetch_assoc(string $sql, bool $log = true, mixed $connection = false): array|false
{
    if (preg_match('/^SHOW COLUMNS FROM (\w+)$/', $sql, $match) !== 1) {
        return false;
    }

    return array_map(static fn(array $column): array => ['Field' => $column['name']], db($connection)->query('PRAGMA table_info(' . $match[1] . ')')->fetchAll(PDO::FETCH_ASSOC));
}

function db_fetch_cell(string $sql, string $column = '', bool $log = true, mixed $connection = false): mixed
{
    return db($connection)->query($sql)->fetchColumn();
}

function db_execute(string $sql, bool $log = true, mixed $connection = false): bool
{
    $state = &$GLOBALS['replicate_out_replacement'];
    $state['statements'][] = $sql;
    $kind = strtok($sql, ' ');
    if ($kind === 'REPLACE') {
        $state['writes']++;
    }
    if ($state['fail'] === $kind && ($kind !== 'REPLACE' || $state['writes'] === $state['fail_write'])) {
        return false;
    }
    // SQLite has no TRUNCATE; DELETE in autocommit mode is the same committed emptying.
    $count = db($connection)->exec(preg_replace('/^TRUNCATE TABLE /', 'DELETE FROM ', $sql));
    if ($count === false) {
        return false;
    }
    $state['affected'] = $count;

    return true;
}

function db_affected_rows(mixed $connection = false): int
{
    return state()['affected'];
}

function db_qstr(mixed $value): string
{
    return state()['local']->quote((string) $value);
}

function cacti_sizeof(mixed $value): int
{
    return is_countable($value) ? count($value) : 0;
}

function array_rekey(array $rows, string $key, string $value): array
{
    return array_column($rows, $value, $key);
}

function cacti_log(string $message, mixed ...$arguments): void
{
    $GLOBALS['replicate_out_replacement']['logs'][] = $message;
}

function replicate_log(string $message, mixed ...$arguments): void
{
    cacti_log($message);
}

function names(PDO $connection): array
{
    return $connection->query('SELECT name FROM data_input ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
}

test('a failed replacement keeps the collector rows that were there before', function (string $engine, string $fail, int $failWrite, int $rows, bool $commits, bool $succeeds, array $expected) {
    $ddl = "CREATE TABLE data_input (id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT '')";
    $local = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
    $remote = $commits ? new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]) : new UnacknowledgedCommit('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
    foreach ([$local, $remote] as $connection) {
        expect($connection->exec($ddl))->not->toBeFalse();
    }
    expect($remote->exec("INSERT INTO data_input (id, name) VALUES (1, 'previous-1'), (2, 'previous-2'), (9, 'removed')"))->toBe(3);
    $insert = $local->prepare('INSERT INTO data_input (id, name) VALUES (?, ?)');
    foreach (range(1, $rows) as $id) {
        $insert->execute([$id, 'row-' . $id]);
    }
    $GLOBALS['replicate_out_replacement'] = ['local' => $local, 'engine' => $engine, 'fail' => $fail, 'fail_write' => $failWrite, 'writes' => 0, 'affected' => 0, 'statements' => [], 'logs' => []];

    $data = $local->query('SELECT * FROM data_input ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $result = replicate_out_table($remote, $data, 'data_input', 2);

    expect($result)->toBe($succeeds)
        ->and($remote->inTransaction())->toBeFalse();
    $names = names($remote);
    if ($expected === ['replacement']) {
        expect($names)->toHaveCount($rows)->and($names[0])->toBe('row-1')->and($names)->not->toContain('removed');
    } elseif ($expected === ['first-batch']) {
        expect($names)->toHaveCount(1001)->and($names[0])->toBe('row-1');
    } else {
        expect($names)->toBe($expected);
    }
    $logs = implode("\n", state()['logs']);
    expect(str_contains($logs, 'Replicated to Remote Poller 2'))->toBe($succeeds);
    if (!$succeeds && $engine === 'InnoDB') {
        expect($logs)->toContain('Replicate Out failed for data_input. The remote rows were kept.');
    }
    expect(str_contains($logs, 'is not InnoDB'))->toBe($engine !== 'InnoDB');
})->with([
    'every row written' => ['InnoDB', '', 0, 2, true, true, ['replacement']],
    'several batches written' => ['InnoDB', '', 0, 2500, true, true, ['replacement']],
    'only insert fails' => ['InnoDB', 'REPLACE', 1, 2, true, false, ['previous-1', 'previous-2', 'removed']],
    'a later batch fails' => ['InnoDB', 'REPLACE', 2, 1500, true, false, ['previous-1', 'previous-2', 'removed']],
    'the delete fails' => ['InnoDB', 'DELETE', 0, 2, true, false, ['previous-1', 'previous-2', 'removed']],
    'the commit is not acknowledged' => ['InnoDB', '', 0, 2, false, false, ['previous-1', 'previous-2', 'removed']],
    // A non-transactional table cannot be rolled back; it keeps the old emptying.
    'MyISAM success' => ['MyISAM', '', 0, 2, true, true, ['replacement']],
    'MyISAM insert fails' => ['MyISAM', 'REPLACE', 1, 2, true, false, []],
    'MyISAM later batch fails' => ['MyISAM', 'REPLACE', 2, 1500, true, false, ['first-batch']],
]);

test('a replacement never joins a transaction the caller already holds', function () {
    $local = new PDO('sqlite::memory:');
    $remote = new PDO('sqlite::memory:');
    foreach ([$local, $remote] as $connection) {
        $connection->exec("CREATE TABLE data_input (id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT '')");
    }
    $remote->exec("INSERT INTO data_input VALUES (1, 'previous')");
    $local->exec("INSERT INTO data_input VALUES (1, 'replacement')");
    $GLOBALS['replicate_out_replacement'] = ['local' => $local, 'engine' => 'InnoDB', 'fail' => '', 'fail_write' => 0, 'writes' => 0, 'affected' => 0, 'statements' => [], 'logs' => []];
    $data = $local->query('SELECT * FROM data_input')->fetchAll(PDO::FETCH_ASSOC);

    expect($remote->beginTransaction())->toBeTrue()
        ->and(replicate_out_table($remote, $data, 'data_input', 2))->toBeFalse()
        ->and(state()['statements'])->toBe([])
        ->and($remote->inTransaction())->toBeTrue();
    $remote->rollBack();
    expect(names($remote))->toBe(['previous']);
});
