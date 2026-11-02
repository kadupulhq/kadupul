<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Persistence\InstallerVersionWriter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Verify the extracted native transaction protocol against actual schema and failure boundaries. */
final class InstallerVersionWriterTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function failures(): iterable
    {
        foreach (['caller-transaction', 'unsupported-driver', 'metadata-query', 'metadata-row', 'metadata-statement-error', 'metadata-close', 'status-query', 'status-driver-error', 'status-engine', 'status-duplicate', 'status-close', 'begin', 'begin-driver-error', 'snapshot-query', 'snapshot-statement-error', 'snapshot-duplicate', 'prepare', 'execute', 'write-statement-error', 'readback-query', 'readback-mismatch', 'readback-statement-error', 'commit', 'commit-driver-error', 'rollback', 'rollback-driver-error', 'rollback-exception', 'driver-exception'] as $failure) {
            yield $failure => [$failure];
        }
    }

    /** Driver failure injection exercises each admission, persistence and cleanup stage. */
    #[DataProvider('failures')]
    public function testDriverFailuresNeverBecomeConfirmedVersions(string $failure): void
    {
        $calls = [];
        $active = $failure === 'caller-transaction';
        $state = '';
        $database = $this->createMock(PDO::class);
        $database->method('getAttribute')->willReturnCallback(static function (int $attribute) use ($failure): string {
            if ($failure === 'driver-exception') {
                throw new \RuntimeException('fixture driver failure');
            }
            return $failure === 'unsupported-driver' ? 'pgsql' : 'mysql';
        });
        // Capture by reference: transaction ownership changes as the actual protocol runs.
        $database->method('inTransaction')->willReturnCallback(static function () use (&$active): bool {
            return $active;
        });
        $database->method('errorCode')->willReturnCallback(static function () use (&$state, $failure): string {
            return $failure === $state . '-driver-error' ? 'HY000' : '00000';
        });
        $statement = function (string $kind) use ($failure): PDOStatement {
            $result = $this->createMock(PDOStatement::class);
            $result->method('errorCode')->willReturn($failure === $kind . '-statement-error' ? 'HY000' : '00000');
            $result->method('closeCursor')->willReturn($failure !== $kind . '-close');
            if ($kind === 'metadata') {
                $result->method('fetch')->willReturn($failure === 'metadata-row' ? false : ['Create Table' => 'CREATE TABLE `version` (`cacti` char(20)) ENGINE=InnoDB']);
            } elseif ($kind === 'status') {
                $row = ['Name' => 'version', 'Engine' => $failure === 'status-engine' ? 'MyISAM' : 'InnoDB'];
                $result->method('fetch')->willReturnOnConsecutiveCalls($row, $failure === 'status-duplicate' ? $row : false);
            } else {
                $result->method('fetchAll')->willReturn($kind === 'snapshot'
                    ? ($failure === 'snapshot-duplicate' ? ['old', 'other'] : ['old'])
                    : ($failure === 'readback-mismatch' ? ['changed'] : ['1.3.0']));
            }
            return $result;
        };
        $database->method('query')->willReturnCallback(static function (string $sql) use (&$calls, &$state, $failure, $statement): PDOStatement|false {
            $state = match ($sql) {
                'SHOW CREATE TABLE `version`' => 'metadata',
                "SHOW TABLE STATUS WHERE Name = 'version'" => 'status',
                'SELECT cacti FROM version FOR UPDATE' => 'snapshot',
                'SELECT cacti FROM version' => 'readback',
                default => throw new \LogicException('Unexpected native SQL'),
            };
            $calls[] = $state;
            return $failure === $state . '-query' ? false : $statement($state);
        });
        $database->method('beginTransaction')->willReturnCallback(static function () use (&$active, &$state, &$calls, $failure): bool {
            $calls[] = $state = 'begin';
            $active = $failure !== 'begin';
            return $active;
        });
        $database->method('prepare')->willReturnCallback(function (string $sql) use (&$calls, $failure): PDOStatement|false {
            $calls[] = 'prepare';
            $this->assertSame('UPDATE version SET cacti = ?', $sql);
            if (in_array($failure, ['prepare', 'rollback', 'rollback-driver-error', 'rollback-exception'], true)) {
                return false;
            }
            $write = $this->createMock(PDOStatement::class);
            $write->method('execute')->willReturnCallback(function (?array $parameters) use (&$calls, $failure): bool {
                $calls[] = 'execute';
                $this->assertSame(['1.3.0'], $parameters);
                return $failure !== 'execute';
            });
            $write->method('errorCode')->willReturn($failure === 'write-statement-error' ? 'HY000' : '00000');
            return $write;
        });
        $database->method('commit')->willReturnCallback(static function () use (&$active, &$state, &$calls, $failure): bool {
            $calls[] = $state = 'commit';
            $active = $failure === 'commit';
            return !$active;
        });
        $database->method('rollBack')->willReturnCallback(static function () use (&$active, &$state, &$calls, $failure): bool {
            $calls[] = $state = 'rollback';
            if ($failure === 'rollback-exception') {
                throw new \RuntimeException('fixture cleanup failure');
            }
            $active = $failure === 'rollback';
            return !$active;
        });
        $this->assertFalse((new InstallerVersionWriter())->record($database, '1.3.0'));
        if ($failure === 'caller-transaction') {
            $this->assertTrue($active);
            $this->assertSame([], $calls);
        } elseif (in_array('begin', $calls, true) && !in_array($failure, ['begin', 'commit-driver-error'], true)) {
            $this->assertContains('rollback', $calls);
        } else {
            $this->assertNotContains('rollback', $calls);
        }
        if (!str_starts_with($failure, 'commit')) {
            $this->assertNotContains('commit', $calls);
        }
    }

    /** Actual SQLite writes retain the empty-marker, retry and idempotency contract. */
    public function testStandaloneServiceConfirmsActualSqliteWrites(): void
    {
        $database = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $database->exec('CREATE TABLE version (cacti TEXT PRIMARY KEY)');
        $writer = new InstallerVersionWriter();
        foreach (['1.2.31', '1.3.0', '1.3.0'] as $version) {
            $this->assertTrue($writer->record($database, $version));
            $this->assertSame([$version], $database->query('SELECT cacti FROM version')->fetchAll(PDO::FETCH_COLUMN));
            $this->assertFalse($database->inTransaction());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function databaseCases(): iterable
    {
        foreach (['success', 'caller-transaction', 'nontransactional', 'temporary-shadow', 'duplicate-markers', 'write-refusal', 'oversized-marker'] as $case) {
            yield $case => [$case];
        }
    }

    /** Real engine tests use the shipped version DDL in an explicitly owned disposable schema. */
    #[DataProvider('databaseCases')]
    public function testActualDatabaseMarkerContracts(string $case): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:') || getenv('INSTALLER_STATE_OWNED_BACKEND') !== '1') {
            $this->markTestSkipped('An explicitly owned disposable MySQL/MariaDB server is required.');
        }
        $user = getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root';
        $password = getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '';
        $administrator = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = 'installer_version_' . bin2hex(random_bytes(10));
        $administrator->exec('CREATE DATABASE `' . $schema . '`');
        $database = null;
        try {
            $database = new PDO(preg_replace('/;dbname=[^;]*/i', '', $dsn) . ';dbname=' . $schema, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
            $source = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
            $this->assertIsString($source);
            $this->assertSame(1, preg_match('/CREATE TABLE version \(.*?;/s', $source, $ddl));
            $this->assertNotFalse($database->exec($ddl[0]));
            $this->assertNotFalse($database->exec("INSERT INTO version VALUES ('1.2.30')"));
            $writer = new InstallerVersionWriter();
            if ($case === 'caller-transaction') {
                $database->exec('CREATE TABLE caller_work (value INT) ENGINE=InnoDB');
                $database->beginTransaction();
                $database->exec('INSERT INTO caller_work VALUES (7)');
                $this->assertFalse($writer->record($database, '1.3.0'));
                $this->assertTrue($database->inTransaction());
                $this->assertSame('7', (string) $database->query('SELECT value FROM caller_work')->fetchColumn());
            } elseif ($case === 'success') {
                foreach (['1.2.31', '1.3.0', '1.3.0'] as $version) {
                    $this->assertTrue($writer->record($database, $version));
                    $this->assertSame([$version], $database->query('SELECT cacti FROM version')->fetchAll(PDO::FETCH_COLUMN));
                }
            } else {
                if ($case === 'nontransactional') {
                    $database->exec('ALTER TABLE version ENGINE=MyISAM');
                } elseif ($case === 'temporary-shadow') {
                    $database->exec(str_replace('CREATE TABLE version', 'CREATE TEMPORARY TABLE version', $ddl[0]));
                } elseif ($case === 'duplicate-markers') {
                    $database->exec("INSERT INTO version VALUES ('1.2.31')");
                } elseif ($case === 'write-refusal') {
                    $database->exec("CREATE TRIGGER reject_version BEFORE UPDATE ON version FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture write refused'");
                }
                $before = $database->query('SELECT cacti FROM version ORDER BY cacti')->fetchAll(PDO::FETCH_COLUMN);
                $this->assertFalse($writer->record($database, $case === 'oversized-marker' ? str_repeat('x', 21) : '1.3.0'));
                $this->assertSame($before, $database->query('SELECT cacti FROM version ORDER BY cacti')->fetchAll(PDO::FETCH_COLUMN));
                $this->assertFalse($database->inTransaction());
                if ($case === 'write-refusal') {
                    $database->exec('DROP TRIGGER reject_version');
                    $this->assertTrue($writer->record($database, '1.3.0'));
                    $this->assertSame(['1.3.0'], $database->query('SELECT cacti FROM version')->fetchAll(PDO::FETCH_COLUMN));
                }
            }
            $this->assertSame($schema, $database->query('SELECT DATABASE()')->fetchColumn());
        } finally {
            if ($database instanceof PDO && $database->inTransaction()) {
                $database->rollBack();
            }
            $database = null;
            $administrator->exec('DROP DATABASE `' . $schema . '`');
        }
    }
}
