<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\IdentityAccess\Infrastructure\Legacy\AuthenticationDatabaseSessionHandler;
use Kadupul\IdentityAccess\Infrastructure\Legacy\BrowserAuthenticationSql;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrowserAuthenticationSqlTest extends TestCase
{
    public function testSuccessfulExecuteWithFailedSqlStateIsRefused(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('errorCode')->willReturn('08006');
        $statement->method('errorInfo')->willReturn(['08006', 7, 'native failure']);
        $database = $this->createMock(PDO::class);
        $database->method('prepare')->willReturn($statement);
        try {
            BrowserAuthenticationSql::execute($database, 'SELECT 1');
            self::fail('A failed driver state must not be accepted.');
        } catch (PDOException $failure) {
            self::assertSame(['08006', 7, 'native failure'], $failure->errorInfo);
            self::assertStringNotContainsString('native failure', $failure->getMessage());
        }
    }

    public function testRealSilentFailedWriteRetainsNativeFailure(): void
    {
        $database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $database->exec('CREATE TABLE sessions (id TEXT PRIMARY KEY)');
        $database->exec("CREATE TRIGGER deny_insert BEFORE INSERT ON sessions BEGIN SELECT RAISE(ABORT, 'refused'); END");
        try {
            BrowserAuthenticationSql::execute($database, 'INSERT INTO sessions VALUES (?)', ['fixture']);
            self::fail('The real failed write must be refused.');
        } catch (PDOException $failure) {
            self::assertSame('23000', $failure->errorInfo[0]);
            self::assertSame(0, (int) $database->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
        }
    }

    public function testIgnoredSessionInsertCannotClaimPersistence(): void
    {
        $database = new PDO('sqlite::memory:');
        $database->exec('CREATE TABLE sessions (id TEXT PRIMARY KEY, remote_addr TEXT, access INTEGER, data TEXT, user_id INTEGER, user_agent TEXT)');
        $database->exec('CREATE TRIGGER ignore_insert BEFORE INSERT ON sessions BEGIN SELECT RAISE(IGNORE); END');
        $connection = new class ($database) implements DatabaseConnection {
            public function __construct(private PDO $database) {}
            public function get(): PDO
            {
                return $this->database;
            }
        };
        $handler = new AuthenticationDatabaseSessionHandler($connection);
        $previous = $_SESSION ?? null;
        $_SESSION = ['sess_user_id' => 9];
        try {
            try {
                $handler->write('generatedfixtureid', 'sess_user_id|i:9;');
                self::fail('A zero-row insertion must not create an authenticated session.');
            } catch (RuntimeException $failure) {
                self::assertFalse($handler->written);
                self::assertSame(0, (int) $database->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
            }
        } finally {
            if ($previous === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $previous;
            }
        }
    }

    public static function unconfirmedStates(): iterable
    {
        yield 'unknown state' => [null];
        yield 'late connection failure' => ['08006'];
        yield 'driver failure' => ['HY000'];
    }

    #[DataProvider('unconfirmedStates')]
    public function testPositiveExecuteCannotConfirmUnknownOrFailedState(?string $state): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('errorCode')->willReturn($state);
        $statement->method('errorInfo')->willReturn([$state, 7, 'native state']);
        $database = $this->createMock(PDO::class);
        $database->method('prepare')->willReturn($statement);
        $this->expectException(PDOException::class);
        BrowserAuthenticationSql::execute($database, 'SELECT 1');
    }

    #[DataProvider('unconfirmedStates')]
    public function testSuccessfulLookingReadCannotConfirmUnknownOrFailedState(?string $state): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn(1);
        $statement->method('errorCode')->willReturn($state);
        $statement->method('errorInfo')->willReturn([$state, 7, 'native state']);
        $this->expectException(PDOException::class);
        BrowserAuthenticationSql::column($statement);
    }

    #[DataProvider('unconfirmedStates')]
    public function testConnectionConfirmationRejectsUnknownOrFailedState(?string $state): void
    {
        $database = $this->createMock(PDO::class);
        $database->method('errorCode')->willReturn($state);
        $database->method('errorInfo')->willReturn([$state, 7, 'native state']);
        try {
            BrowserAuthenticationSql::confirmConnection($database);
            self::fail('Unknown or failed connection state must be refused.');
        } catch (PDOException $failure) {
            self::assertSame([$state, 7, 'native state'], $failure->errorInfo);
        }
    }

    public function testRealSuccessfulOperationsHaveConfirmedNativeStates(): void
    {
        $database = new PDO('sqlite::memory:');
        $statement = BrowserAuthenticationSql::execute($database, 'SELECT 1');
        self::assertSame('00000', $statement->errorCode());
        self::assertSame(1, BrowserAuthenticationSql::column($statement));
        self::assertSame('00000', $statement->errorCode());
        self::assertTrue($database->beginTransaction());
        self::assertSame('00000', $database->errorCode());
        BrowserAuthenticationSql::confirmConnection($database);
        self::assertTrue($database->commit());
        self::assertSame('00000', $database->errorCode());
        BrowserAuthenticationSql::confirmConnection($database);
        self::assertTrue($database->beginTransaction());
        self::assertTrue($database->rollBack());
        self::assertSame('00000', $database->errorCode());
        BrowserAuthenticationSql::confirmConnection($database);
    }

}
