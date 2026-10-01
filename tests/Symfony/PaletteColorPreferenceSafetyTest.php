<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyPaletteColorPreferences;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyPaletteColorAccess;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Tests\Fixtures\RealMariaDb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaletteColorPreferenceSafetyTest extends TestCase
{
    use RealMariaDb;

    public function testDirectRemotePreferenceSaveCannotBeginOrWrite(): void
    {
        foreach ([2, 0, null, '1', false] as $collector) {
            $db = new \PDO('sqlite::memory:');
            $db->exec("CREATE TABLE settings_user(user_id INT,name TEXT,value TEXT,PRIMARY KEY(user_id,name)); INSERT INTO settings_user VALUES(9,'palette_colors_filters','before')");
            try {
                $this->preferences($db, $collector)->save(['filter' => 'after']);
                self::fail('Remote or malformed collector preference save accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Filter preferences require the primary collector.', $error->getMessage());
            }
            self::assertFalse($db->inTransaction());
            self::assertSame('before', $db->query('SELECT value FROM settings_user')->fetchColumn());
        }
    }

    public function testCallerOwnedPreferenceWorkRemainsUnchanged(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE preserved(value TEXT)');
        $db->beginTransaction();
        $db->exec("INSERT INTO preserved VALUES('caller')");
        try {
            $this->preferences($db, 1)->save(['filter' => 'after']);
            self::fail('Caller transaction accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Filter preference transaction unavailable.', $error->getMessage());
        }
        self::assertTrue($db->inTransaction());
        self::assertSame('caller', $db->query('SELECT value FROM preserved')->fetchColumn());
        $db->rollBack();
    }

    public function testNativeCallerTransactionKeepsItsPendingRows(): void
    {
        $connection = $this->realMariaDb();
        $db = $connection->getNativeConnection();
        $schema = 'palette_pref_' . bin2hex(random_bytes(6));
        $db->exec('CREATE DATABASE `' . $schema . '`');
        $db->exec('USE `' . $schema . '`');
        try {
            $db->exec('CREATE TABLE preserved(value VARCHAR(32)) ENGINE=InnoDB');
            $db->beginTransaction();
            $db->exec("INSERT INTO preserved VALUES('caller')");
            try {
                $this->preferences($db, 1)->save(['filter' => 'after']);
                self::fail('Native caller transaction accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Filter preference transaction unavailable.', $error->getMessage());
            }
            self::assertTrue($db->inTransaction());
            self::assertSame('caller', $db->query('SELECT value FROM preserved')->fetchColumn());
            $db->rollBack();
            self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM preserved')->fetchColumn());
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $db->exec('DROP DATABASE `' . $schema . '`');
            $connection->close();
        }
    }

    #[DataProvider('unconfirmedPreflight')]
    public function testUnconfirmedStoragePreflightNeverBeginsOrAuthorizes(string $failure): void
    {
        $db = $this->createMock(\PDO::class);
        $db->method('inTransaction')->willReturn(false);
        $db->method('getAttribute')->with(\PDO::ATTR_DRIVER_NAME)->willReturn('mysql');
        $db->expects(self::never())->method('beginTransaction');
        $db->expects(self::never())->method('prepare');
        if ($failure === 'show-create') {
            $db->expects(self::once())->method('query')->willReturn(false);
            $db->expects(self::never())->method('exec');
        } else {
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('fetch')->willReturn(['settings_user', "CREATE TABLE settings_user (id INT)\n) ENGINE=InnoDB"]);
            $statement->method('errorCode')->willReturn($failure === 'late-fetch' ? 'HY000' : '00000');
            $statement->method('errorInfo')->willReturn(['HY000', 2013, 'Fixture late read failure']);
            $db->expects(self::exactly($failure === 'late-fetch' ? 1 : 7))->method('query')->willReturn($statement);
            if ($failure === 'late-fetch') {
                $db->expects(self::never())->method('exec');
            } else {
                $db->expects(self::once())->method('exec')->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->willReturn($failure === 'isolation-false' ? false : 0);
                $db->method('errorCode')->willReturn('HY000');
            }
        }
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($db);
        $access = $this->createMock(PaletteColorAccess::class);
        $access->expects(self::never())->method('authorize');
        $access->expects(self::never())->method('assertCurrent');
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $preferences = new LegacyPaletteColorPreferences($access, $database, $configuration);
        $this->expectException(\RuntimeException::class);
        $preferences->save(['filter' => 'after']);
    }

    public static function unconfirmedPreflight(): array
    {
        return [['show-create'], ['late-fetch'], ['isolation-false'], ['isolation-error-state']];
    }

    public static function storageParticipants(): iterable
    {
        foreach (['settings_user', 'settings', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm'] as $table) {
            yield $table => [$table, false];
            yield $table . ' temporary shadow' => [$table, true];
        }
    }

    #[DataProvider('storageParticipants')]
    public function testEveryActualNontransactionalParticipantRefusesBeforeWrite(string $table, bool $shadow): void
    {
        $connection = $this->realMariaDb();
        $db = $connection->getNativeConnection();
        $schema = 'palette_pref_' . bin2hex(random_bytes(6));
        $db->exec('CREATE DATABASE `' . $schema . '`');
        $db->exec('USE `' . $schema . '`');
        try {
            foreach (['settings_user', 'settings', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm'] as $name) {
                $columns = $name === 'settings_user' ? 'user_id INT,name VARCHAR(128),value TEXT,PRIMARY KEY(user_id,name)' : 'id INT PRIMARY KEY';
                $db->exec('CREATE TABLE `' . $name . '` (' . $columns . ') ENGINE=InnoDB');
            }
            if ($shadow) {
                $columns = $table === 'settings_user' ? 'user_id INT,name VARCHAR(128),value TEXT,PRIMARY KEY(user_id,name)' : 'id INT PRIMARY KEY';
                $db->exec('CREATE TEMPORARY TABLE `' . $table . '` (' . $columns . ') ENGINE=MyISAM');
            } else {
                $db->exec('ALTER TABLE `' . $table . '` ENGINE=MyISAM');
            }
            $db->exec("INSERT INTO settings_user VALUES(9,'palette_colors_filters','before')");
            try {
                $this->preferences($db, 1)->save(['filter' => 'after']);
                self::fail('Nontransactional preference participant accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Filter preferences require transactional tables: ' . $table, $error->getMessage());
            }
            self::assertFalse($db->inTransaction());
            self::assertSame('before', $db->query('SELECT value FROM settings_user')->fetchColumn());
        } finally {
            if ($db->inTransaction()) $db->rollBack();
            $db->exec('DROP DATABASE `' . $schema . '`');
            $connection->close();
        }
    }

    public function testPrimaryPreferenceCommitAcceptsAbsentOptionalGroupsInBothPdoModes(): void
    {
        foreach ([\PDO::ERRMODE_EXCEPTION, \PDO::ERRMODE_SILENT] as $mode) {
            $connection = $this->realMariaDb();
            $db = $connection->getNativeConnection();
            $schema = 'palette_pref_' . bin2hex(random_bytes(6));
            $db->exec('CREATE DATABASE `' . $schema . '`');
            $db->exec('USE `' . $schema . '`');
            try {
                $db->exec('CREATE TABLE settings_user (user_id INT,name VARCHAR(128),value TEXT,PRIMARY KEY(user_id,name)) ENGINE=InnoDB');
                $db->exec('CREATE TABLE settings (name VARCHAR(128) PRIMARY KEY,value TEXT) ENGINE=InnoDB');
                $db->exec('CREATE TABLE user_auth (id INT PRIMARY KEY,username VARCHAR(128),enabled VARCHAR(2),locked VARCHAR(2),must_change_password VARCHAR(2)) ENGINE=InnoDB');
                $db->exec('CREATE TABLE user_auth_realm (user_id INT,realm_id INT,PRIMARY KEY(user_id,realm_id)) ENGINE=InnoDB');
                $db->exec("INSERT INTO settings VALUES('auth_method','1'),('guest_user','0'); INSERT INTO user_auth VALUES(9,'operator','on','',''); INSERT INTO user_auth_realm VALUES(9,5),(9,8)");
                $db->exec("INSERT INTO settings_user VALUES(9,'palette_colors_filters','before')");
                $db->setAttribute(\PDO::ATTR_ERRMODE, $mode);
                $this->preferences($db, 1, true)->save(['filter' => 'after']);
                self::assertFalse($db->inTransaction());
                self::assertSame(['filter' => 'after'], json_decode($db->query('SELECT value FROM settings_user')->fetchColumn(), true, flags: JSON_THROW_ON_ERROR));
            } finally {
                $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                if ($db->inTransaction()) $db->rollBack();
                $db->exec('DROP DATABASE `' . $schema . '`');
                $connection->close();
            }
        }
    }

    private function preferences(\PDO $db, mixed $collector, bool $realAccess = false): LegacyPaletteColorPreferences
    {
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($db);
        $access = $this->createMock(PaletteColorAccess::class);
        $access->method('authorize')->willReturn(new Actor(9, 'operator'));
        if ($realAccess) {
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn(new Actor(9, 'operator'));
            $access = new LegacyPaletteColorAccess($console, $database);
        }
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => $collector]);
        return new LegacyPaletteColorPreferences($access, $database, $configuration);
    }
}
