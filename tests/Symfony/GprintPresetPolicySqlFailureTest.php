<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyGprintPresetAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyGprintPresetPreferences;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GprintPresetPolicySqlFailureTest extends TestCase
{
    #[DataProvider('policyReads')]
    public function testLatePolicyReadCannotAuthorizeOrFallThroughToGroup(string $target): void
    {
        $database = $this->createMock(\PDO::class);
        $database->method('inTransaction')->willReturn(false);
        $database->method('getAttribute')->willReturn('sqlite');
        $factory = function (string $sql) use ($target): \PDOStatement {
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $failed = false;
            // Capture by reference: the SQLSTATE changes only during the fetch.
            $statement->method('errorCode')->willReturnCallback(static function () use (&$failed): string {
                return $failed ? '08006' : '00000';
            });
            $statement->method('fetch')->willReturn(['id' => 9, 'username' => 'operator', 'enabled' => 'on', 'locked' => '', 'must_change_password' => '']);
            $statement->method('fetchColumn')->willReturnCallback(static function () use (&$failed, $sql, $target): string|int|false {
                if (str_contains($sql, $target)) {
                    $failed = true;
                    return false;
                }
                return str_contains($sql, 'guest_user') ? '0' : 1;
            });
            return $statement;
        };
        $database->method('prepare')->willReturnCallback($factory);
        $database->method('query')->willReturnCallback($factory);
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn(new Actor(9, 'operator'));
        $this->expectException(\RuntimeException::class);
        (new LegacyGprintPresetAccess($console, $this->connection($database)))->authorize();
    }

    public static function policyReads(): array
    {
        return [['auth_method'], ['guest_user'], ['FROM user_auth_realm']];
    }

    public function testSilentPreferenceWriteFailureRollsBackInsteadOfReportingSuccess(): void
    {
        $database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);
        $database->exec('CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT)');
        $database->exec("INSERT INTO settings_user VALUES (9, 'gprint_presets_filters', '{\"page\":\"1\"}')");
        $database->exec("CREATE TRIGGER refuse_preferences BEFORE INSERT ON settings_user BEGIN SELECT RAISE(FAIL, 'fixture refusal'); END");
        $preferences = $this->preferences($database);
        $failure = null;
        try {
            $preferences->save(['page' => '2']);
        } catch (\RuntimeException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertFalse($database->inTransaction());
        self::assertSame('{"page":"1"}', $database->query('SELECT value FROM settings_user')->fetchColumn());
    }

    public function testSilentPreferenceReadFailureIsNotAnAbsentPreference(): void
    {
        $database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);
        $database->exec("CREATE VIEW settings_user AS SELECT CAST(9 AS INTEGER) user_id, 'gprint_presets_filters' name, json_extract('bad json', '$') value");
        $this->expectException(\RuntimeException::class);
        $this->preferences($database)->load();
    }

    public function testConfirmedAbsentPreferencesStillReturnNull(): void
    {
        $database = new \PDO('sqlite::memory:');
        $database->exec('CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT)');
        self::assertNull($this->preferences($database)->load());
    }

    #[DataProvider('uncertainTransactions')]
    public function testUnconfirmedPreferenceCommitOrRollbackIsAnInfrastructureFailure(string $failure): void
    {
        $database = $this->createMock(\PDO::class);
        $database->method('getAttribute')->with(\PDO::ATTR_DRIVER_NAME)->willReturn('sqlite');
        $database->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $database->expects(self::once())->method('beginTransaction')->willReturn(true);
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn($failure === 'commit');
        $database->method('prepare')->willReturn($statement);
        $database->expects($failure === 'commit' ? self::once() : self::never())->method('commit')->willReturn(false);
        $rollback = $database->expects(self::once())->method('rollBack');
        if ($failure === 'rollback throw') {
            $rollback->willThrowException(new \RuntimeException('Fixture rollback exception'));
        } else {
            $rollback->willReturn($failure === 'commit');
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($failure === 'commit' ? 'commit could not be confirmed' : 'rollback could not be confirmed');
        $this->preferences($database)->save(['page' => '2']);
    }

    public static function uncertainTransactions(): array
    {
        return [['commit'], ['rollback false'], ['rollback throw']];
    }

    public function testLatePreferenceReadFailureDoesNotReturnDefaults(): void
    {
        $database = $this->createMock(\PDO::class);
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $failed = false;
        $statement->method('fetchColumn')->willReturnCallback(static function () use (&$failed): false {
            $failed = true;
            return false;
        });
        $statement->method('errorCode')->willReturnCallback(static function () use (&$failed): string {
            return $failed ? '08006' : '00000';
        });
        $database->method('prepare')->willReturn($statement);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GPRINT database result could not be confirmed.');
        $this->preferences($database)->load();
    }

    public function testRealSilentDirectRealmFailureCannotFallThroughToAnEnabledGroup(): void
    {
        $database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);
        $database->exec('CREATE TABLE user_auth (id INTEGER, username TEXT, enabled TEXT, locked TEXT, must_change_password TEXT)');
        $database->exec("INSERT INTO user_auth VALUES (9,'operator','on','','')");
        $database->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $database->exec("INSERT INTO settings VALUES ('auth_method','1'), ('guest_user','0')");
        $database->exec("CREATE VIEW user_auth_realm AS SELECT CAST(9 AS INTEGER) user_id, json_extract('invalid json', '$') realm_id");
        $database->exec('CREATE TABLE user_auth_group (id INTEGER, enabled TEXT)');
        $database->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
        $database->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');
        $database->exec("INSERT INTO user_auth_group VALUES (3,'on'); INSERT INTO user_auth_group_members VALUES (3,9); INSERT INTO user_auth_group_realm VALUES (3,5)");
        $query = $database->prepare('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?');
        self::assertFalse($query->execute([9, 5]));
        self::assertSame('HY000', $query->errorCode());
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn(new Actor(9, 'operator'));
        $this->expectException(\RuntimeException::class);
        (new LegacyGprintPresetAccess($console, $this->connection($database)))->authorize();
    }

    public static function unsafePreferenceStorage(): iterable
    {
        yield 'secondary collector' => [2, 'InnoDB', '00000'];
        yield 'nontransactional table' => [1, 'MyISAM', '00000'];
        yield 'unconfirmed engine read' => [1, 'InnoDB', 'HY000'];
        yield 'unknown engine read state' => [1, 'InnoDB', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafePreferenceStorage')]
    public function testPreferencePreflightRefusesBeforeBeginning(int $collector, string $engine, ?string $state): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->with(\PDO::ATTR_DRIVER_NAME)->willReturn('mysql');
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->expects(self::never())->method('beginTransaction');
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('fetch')->willReturn(['settings_user', "CREATE TABLE settings_user (id int\n) ENGINE=" . $engine]);
        $statement->method('errorCode')->willReturn($state);
        $pdo->method('query')->willReturn($statement);
        $configuration = $this->createMock(\Kadupul\Platform\Contract\LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => $collector]);
        $access = $this->createMock(GprintPresetAccess::class);
        $access->expects(self::never())->method('authorize');
        $preferences = new LegacyGprintPresetPreferences($access, $this->connection($pdo), $configuration);
        $this->expectException(\RuntimeException::class);
        $preferences->save(['rows' => '30']);
    }

    private function preferences(\PDO $database): LegacyGprintPresetPreferences
    {
        $access = $this->createMock(GprintPresetAccess::class);
        $access->method('authorize')->willReturn(new Actor(9, 'operator'));
        $configuration = $this->createMock(\Kadupul\Platform\Contract\LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        return new LegacyGprintPresetPreferences($access, $this->connection($database), $configuration);
    }

    private function connection(\PDO $database): DatabaseConnection
    {
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->method('get')->willReturn($database);
        return $connection;
    }
}
