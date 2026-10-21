<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace PollerReindexBufferDatabase;

use PDO;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 2) . '/lib/reference_write.php';

$source = file_get_contents(dirname(__DIR__, 2) . '/lib/poller.php');
if ($source === false) {
    throw new \RuntimeException('Cannot read production reindex buffer helper.');
}
eval('namespace ' . __NAMESPACE__ . '; use RuntimeException;' . \test_php_function_source($source, 'poller_update_poller_reindex_from_buffer'));

function db_execute_prepared(string $sql, array $parameters = []): bool
{
    $state = &$GLOBALS['reindex_buffer_test'];
    $kind = strtolower(strtok($sql, ' '));
    $state['calls'][] = $kind;
    if ($kind === 'insert') {
        $state['inserts']++;
    }
    if ($state['failure'] === $kind || ($state['failure'] === 'late-insert' && $kind === 'insert' && $state['inserts'] === 2)) {
        // Return an actual silent PDO failure, as the legacy database adapter does.
        $sql = 'SELECT * FROM owned_missing_reindex_fixture';
        $parameters = [];
    }
    $query = $state['database']->prepare($sql);

    return $query !== false && $query->execute($parameters);
}

function db_execute(string $sql): bool
{
    return db_execute_prepared($sql);
}

function poller_push_reindex_only_data_to_main($hostId, $queryId): void
{
    $GLOBALS['reindex_buffer_test']['publications'][] = [$hostId, $queryId];
}

final class PollerReindexBufferDatabaseTest extends TestCase
{
    private PDO $database;
    private string $schema;
    /** @var array<string, array{bool, mixed}> */
    private array $previousGlobals = [];

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
            self::markTestSkipped('An explicitly configured disposable MySQL/MariaDB server is required.');
        }
        $this->database = new PDO(
            $dsn,
            getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'),
            getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->schema = 'kadupul_reindex_' . bin2hex(random_bytes(8));
        $this->database->exec("CREATE DATABASE `{$this->schema}`");
        $this->database->exec("USE `{$this->schema}`");
        foreach (['database_sessions', 'database_hostname', 'database_port', 'database_default', 'reindex_buffer_test'] as $key) {
            $this->previousGlobals[$key] = [array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null];
        }
        try {
            $schema = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
            self::assertIsString($schema);
            self::assertSame(1, preg_match('/CREATE TABLE poller_reindex \(.*?;\n/s', $schema, $match));
            $this->database->exec($match[0]);
            $this->database->exec("INSERT INTO poller_reindex VALUES (1,2,0,1,'=','old','old'), (1,3,0,1,'=','other-query','other'), (2,2,0,1,'=','other-host','other')");
            $GLOBALS['database_hostname'] = 'owned-reindex';
            $GLOBALS['database_port'] = 0;
            $GLOBALS['database_default'] = $this->schema;
            $GLOBALS['database_sessions'] = ['owned-reindex:0:' . $this->schema => $this->database];
            $GLOBALS['reindex_buffer_test'] = ['database' => $this->database, 'failure' => '', 'calls' => [], 'inserts' => 0, 'publications' => []];
            $this->database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        } catch (\Throwable $error) {
            $this->tearDown();
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->database, $this->schema)) {
            if ($this->database->inTransaction()) {
                self::assertTrue($this->database->rollBack());
            }
            self::assertNotFalse($this->database->exec("DROP DATABASE `{$this->schema}`"));
        }
        foreach ($this->previousGlobals as $key => [$existed, $value]) {
            if ($existed) {
                $GLOBALS[$key] = $value;
            } else {
                unset($GLOBALS[$key]);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $query = $this->database->query('SELECT * FROM poller_reindex ORDER BY host_id, data_query_id, arg1');
        self::assertNotFalse($query);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<string> */
    private function buffer(int $count = 1): array
    {
        $buffer = [];
        for ($i = 0; $i < $count; $i++) {
            $buffer[] = "(1,2,1,'='," . $this->database->quote(str_repeat('v', 100)) . ',' . $this->database->quote($i . str_repeat('a', 220)) . ',1)';
        }

        return $buffer;
    }

    /** @return array<string, array{string}> */
    public static function failures(): array
    {
        return array_combine(['update', 'insert', 'late-insert', 'delete'], array_map(static fn(string $kind): array => [$kind], ['update', 'insert', 'late-insert', 'delete']));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testFailedWritesRetainThePreviousCacheAndNeverPublish(string $failure): void
    {
        $before = $this->rows();
        $buffer = $this->buffer($failure === 'late-insert' ? 1000 : 1);
        $original = $buffer;
        $GLOBALS['reindex_buffer_test']['failure'] = $failure;
        $error = null;
        try {
            poller_update_poller_reindex_from_buffer(1, 2, $buffer);
        } catch (\RuntimeException $exception) {
            $error = $exception;
        }
        self::assertSame($before, $this->rows(), $failure . ' must preserve the previous cache');
        self::assertNotNull($error, $failure . ' must report failure');
        self::assertSame([], $GLOBALS['reindex_buffer_test']['publications']);
        self::assertSame($original, $buffer);
        self::assertFalse($this->database->inTransaction());
        self::assertSame($failure === 'late-insert' ? ['update', 'insert', 'insert'] : match ($failure) {
            'update' => ['update'],
            'insert' => ['update', 'insert'],
            default => ['update', 'insert', 'delete'],
        }, $GLOBALS['reindex_buffer_test']['calls']);
    }

    public function testSuccessfulReplacementIsScopedRepeatableAndCanClearExplicitly(): void
    {
        $buffer = $this->buffer(1000);
        poller_update_poller_reindex_from_buffer(1, 2, $buffer);
        self::assertCount(1002, $this->rows());
        self::assertFalse($this->database->inTransaction());
        self::assertSame([[1, 2]], $GLOBALS['reindex_buffer_test']['publications']);
        $before = $this->rows();
        poller_update_poller_reindex_from_buffer(1, 2, $buffer);
        self::assertSame($before, $this->rows());
        $buffer = [];
        poller_update_poller_reindex_from_buffer(1, 2, $buffer);
        $rows = $this->rows();
        self::assertCount(2, $rows);
        self::assertSame(['other-query', 'other-host'], array_column($rows, 'assert_value'));
    }

    public function testSuccessfulReplacementLeavesCommitOwnershipWithTheCaller(): void
    {
        $before = $this->rows();
        self::assertTrue($this->database->beginTransaction());
        $buffer = $this->buffer();
        poller_update_poller_reindex_from_buffer(1, 2, $buffer);
        self::assertTrue($this->database->inTransaction());
        self::assertNotSame($before, $this->rows());
        self::assertTrue($this->database->rollBack());
        self::assertSame($before, $this->rows());
    }

    public function testNontransactionalCacheIsRejectedBeforeMutationOrPublication(): void
    {
        self::assertNotFalse($this->database->exec('ALTER TABLE poller_reindex ENGINE=MyISAM'));
        $before = $this->rows();
        $buffer = $this->buffer();
        $error = null;
        try {
            poller_update_poller_reindex_from_buffer(1, 2, $buffer);
        } catch (\RuntimeException $exception) {
            $error = $exception;
        }
        self::assertNotNull($error);
        self::assertSame($before, $this->rows());
        self::assertSame([], $GLOBALS['reindex_buffer_test']['calls']);
        self::assertSame([], $GLOBALS['reindex_buffer_test']['publications']);
    }

    public function testFailurePreservesCallerTransactionAndEarlierWork(): void
    {
        self::assertTrue($this->database->beginTransaction());
        self::assertNotFalse($this->database->exec("UPDATE poller_reindex SET assert_value='caller' WHERE host_id=2"));
        $before = $this->rows();
        $GLOBALS['reindex_buffer_test']['failure'] = 'delete';
        $buffer = $this->buffer();
        $error = null;
        try {
            poller_update_poller_reindex_from_buffer(1, 2, $buffer);
        } catch (\RuntimeException $exception) {
            $error = $exception;
        }
        self::assertNotNull($error);
        self::assertSame($before, $this->rows());
        self::assertTrue($this->database->inTransaction());
        self::assertSame([], $GLOBALS['reindex_buffer_test']['publications']);
        self::assertTrue($this->database->rollBack());
        self::assertSame(['old', 'other-query', 'other-host'], array_column($this->rows(), 'assert_value'));
    }
}
