<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Kadupul\Platform\Infrastructure\Legacy\Dbal\LegacyDatabaseCall;
use Kadupul\Platform\Infrastructure\Legacy\Dbal\LegacyDatabaseConnection;
use PHPUnit\Framework\TestCase;

/**
 * Engine-independent checks; tests/integration/LegacyDbalConnectionDatabaseTest
 * proves the same contracts against MariaDB and MySQL with lib/database.php.
 */
final class LegacyDatabaseConnectionTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $this->pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec("INSERT INTO items (name) VALUES ('one')");
    }

    public function testDbalRunsOnTheGivenHandleAndLeavesItSilent(): void
    {
        $connection = LegacyDatabaseConnection::open($this->pdo);
        self::assertSame('one', $connection->fetchOne('SELECT name FROM items WHERE id = ?', [1]));
        self::assertSame($this->pdo, $connection->getNativeConnection());
        self::assertSame(\PDO::ERRMODE_SILENT, $this->pdo->getAttribute(\PDO::ATTR_ERRMODE));
        foreach ([static fn(Connection $c) => $c->fetchOne('SELECT nope FROM missing'), static fn(Connection $c) => $c->executeStatement('UPDATE missing SET x = 1'), static fn(Connection $c) => $c->executeStatement('DROP TABLE missing'), static fn(Connection $c) => $c->commit()] as $failing) {
            try {
                $failing($connection);
                self::fail('A failing call must raise.');
            } catch (DbalException) {
            }
            self::assertSame(\PDO::ERRMODE_SILENT, $this->pdo->getAttribute(\PDO::ATTR_ERRMODE));
            self::assertFalse($this->pdo->query('SELECT nope FROM missing'));
        }
    }

    public function testFailuresReturnTheHelperValuesAndAlwaysLog(): void
    {
        $lines = [];
        $call = new LegacyDatabaseCall(static function (string $line, bool $output, string $environ, int|string $level) use (&$lines): void {
            $lines[] = [$line, $environ, $level];
        });
        $fail = static fn(Connection $c) => $c->fetchOne('SELECT nope FROM missing');
        self::assertFalse($call->cell($fail, true, $this->pdo));
        self::assertFalse($call->row($fail, true, $this->pdo));
        self::assertFalse($call->assoc($fail, true, $this->pdo));
        self::assertFalse($call->execute($fail, true, $this->pdo));
        self::assertCount(8, $lines);
        self::assertSame(['DBCALL', 5], array_slice($lines[0], 1));
        self::assertSame(['DBCALL', ''], array_slice($lines[1], 1));
        self::assertStringStartsWith('ERROR: A DB Exec Failed!, Error: ', $lines[6][0]);
        self::assertStringContainsString("SQL: 'SELECT nope FROM missing'", $lines[6][0]);
        $lines = [];
        self::assertFalse($call->cell($fail, false, $this->pdo));
        self::assertCount(1, $lines);
        self::assertSame(\PDO::ERRMODE_SILENT, $this->pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }

    public function testEmptyResultsKeepTheHelperShapes(): void
    {
        $call = new LegacyDatabaseCall(static fn() => self::fail('Nothing failed.'));
        self::assertFalse($call->cell(static fn(Connection $c) => $c->fetchOne('SELECT name FROM items WHERE id = 9'), true, $this->pdo));
        self::assertSame([], $call->row(static fn(Connection $c) => $c->fetchAssociative('SELECT name FROM items WHERE id = 9'), true, $this->pdo));
        self::assertSame([], $call->assoc(static fn(Connection $c) => $c->fetchAllAssociative('SELECT name FROM items WHERE id = 9'), true, $this->pdo));
        self::assertTrue($call->execute(static fn(Connection $c) => $c->executeStatement('UPDATE items SET name = name'), true, $this->pdo));
    }

    public function testOtherExceptionsAreNotTranslated(): void
    {
        $this->expectException(\DomainException::class);
        (new LegacyDatabaseCall(static fn() => self::fail('Not a database failure.')))->cell(static fn() => throw new \DomainException('refused'), true, $this->pdo);
    }

    /**
     * The bridge reuses a session; it must have no way to make one or to
     * learn the credentials another one would need.
     */
    public function testTheBridgeCannotDialOrReadCredentials(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/src/Platform/Infrastructure/Legacy/Dbal/*.php');
        self::assertCount(7, $files);
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach (['new \PDO', 'new PDO', 'DriverManager', 'config.php', 'InstallationConfiguration', 'InstallationDatabase', 'database_password', 'database_username', 'database_details', 'db_connect_real', 'db_check_reconnect', 'include', 'require'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, basename($file));
            }
        }
    }
}
