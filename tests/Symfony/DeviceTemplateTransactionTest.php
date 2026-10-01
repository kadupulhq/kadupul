<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateTransaction;
use PHPUnit\Framework\TestCase;

final class DeviceTemplateTransactionTest extends TestCase
{
    public function testCallerTransactionRemainsActiveAndItsWritesSurviveRejection(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE preserved (value TEXT)');
        $db->beginTransaction();
        $db->exec("INSERT INTO preserved VALUES ('caller')");
        try {
            DeviceTemplateTransaction::begin($db, ['poller_id' => 1], ['settings_user']);
            self::fail('Caller transaction accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Caller-owned transaction.', $error->getMessage());
        }
        self::assertTrue($db->inTransaction());
        self::assertSame('caller', $db->query('SELECT value FROM preserved')->fetchColumn());
        $db->rollBack();
    }

    public function testRememberPreferencesRejectsCallerTransactionWithoutRollingItBack(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE preserved (value TEXT)');
        $db->beginTransaction();
        $db->exec("INSERT INTO preserved VALUES ('owned')");
        $database = $this->createMock(\Kadupul\Platform\Contract\DatabaseConnection::class);
        $database->method('get')->willReturn($db);
        $configuration = $this->createMock(\Kadupul\Platform\Contract\LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['poller_id' => 1]);
        $adapter = new \Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceTemplateDefinitions($database, dirname(__DIR__, 2), $configuration);
        try {
            $adapter->remember(42, []);
            self::fail('Preferences joined a caller transaction.');
        } catch (\RuntimeException $error) {
            self::assertSame('Caller-owned transaction.', $error->getMessage());
        }
        self::assertTrue($db->inTransaction());
        self::assertSame('owned', $db->query('SELECT value FROM preserved')->fetchColumn());
        $db->rollBack();
    }

    public function testTransactionOperationFailuresCannotReportConfirmation(): void
    {
        $begin = $this->createMock(\PDO::class);
        $begin->method('getAttribute')->willReturn('sqlite');
        $begin->method('inTransaction')->willReturn(false);
        $begin->method('beginTransaction')->willReturn(false);
        try {
            DeviceTemplateTransaction::begin($begin, [], []);
            self::fail('False begin accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Transaction start was not confirmed.', $error->getMessage());
        }
        foreach (['commit', 'rollback'] as $operation) {
            foreach ([false, true] as $active) {
                $db = $this->createMock(\PDO::class);
                $db->method('inTransaction')->willReturn($active);
                $db->expects($active ? self::once() : self::never())->method($operation === 'rollback' ? 'rollBack' : 'commit')->willReturn(false);
                try {
                    DeviceTemplateTransaction::$operation($db);
                    self::fail('Unconfirmed ' . $operation . ' accepted.');
                } catch (\RuntimeException $error) {
                    self::assertSame('Transaction ' . $operation . ' was not confirmed.', $error->getMessage());
                }
            }
        }
        foreach (['commit', 'rollback'] as $operation) {
            $db = $this->createMock(\PDO::class);
            $db->method('inTransaction')->willReturn(true);
            $db->expects(self::once())->method($operation === 'rollback' ? 'rollBack' : 'commit')->willReturn(true);
            DeviceTemplateTransaction::$operation($db);
        }
    }

    public function testStorageInspectionAndIsolationFalseNeverBeginOrAuthorizePreferences(): void
    {
        foreach (['inspection', 'isolation'] as $failure) {
            $db = $this->createMock(\PDO::class);
            $db->method('inTransaction')->willReturn(false);
            $db->method('getAttribute')->willReturn('mysql');
            if ($failure === 'inspection') {
                $db->expects(self::once())->method('query')->with('SHOW CREATE TABLE `settings`')->willReturn(false);
                $db->expects(self::never())->method('exec');
            } else {
                $statement = $this->createMock(\PDOStatement::class);
                $statement->method('fetch')->willReturn(['table', "CREATE TABLE `table` (\n `id` int\n) ENGINE=InnoDB"]);
                $db->method('query')->willReturn($statement);
                $db->expects(self::once())->method('exec')->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->willReturn(false);
            }
            $db->expects(self::never())->method('beginTransaction');
            $db->expects(self::never())->method('prepare');
            $db->expects(self::never())->method('commit');
            $db->expects(self::never())->method('rollBack');
            $database = $this->createMock(\Kadupul\Platform\Contract\DatabaseConnection::class);
            $database->method('get')->willReturn($db);
            $configuration = $this->createMock(\Kadupul\Platform\Contract\LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['poller_id' => 1]);
            $adapter = new \Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceTemplateDefinitions($database, dirname(__DIR__, 2), $configuration);
            try {
                $adapter->remember(42, []);
                self::fail('Unconfirmed precondition accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame($failure === 'inspection' ? 'Storage inspection was not confirmed.' : 'Transaction isolation was not confirmed.', $error->getMessage());
            }
        }
    }

    public function testRemoteCollectorRejectsBeforeInspectingOrChangingStorage(): void
    {
        $db = $this->createMock(\PDO::class);
        $db->method('inTransaction')->willReturn(false);
        $db->method('getAttribute')->with(\PDO::ATTR_DRIVER_NAME)->willReturn('mysql');
        $db->expects(self::never())->method('query');
        $db->expects(self::never())->method('exec');
        $db->expects(self::never())->method('beginTransaction');
        $this->expectExceptionMessage('Device templates require the primary collector.');
        DeviceTemplateTransaction::begin($db, ['poller_id' => 2], ['settings_user']);
    }

    public function testStorageChecksTheActualConnectionTableAndAllAuthorizationDependencies(): void
    {
        $db = $this->createMock(\PDO::class);
        $db->method('inTransaction')->willReturn(false);
        $db->method('getAttribute')->willReturn('mysql');
        $inspected = [];
        $db->method('query')->willReturnCallback(function (string $sql) use (&$inspected): \PDOStatement {
            $inspected[] = $sql;
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('fetch')->willReturn(['table', "CREATE TABLE `table` (\n `id` int\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"]);
            return $statement;
        });
        $db->expects(self::once())->method('exec')->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->willReturn(0);
        $db->expects(self::once())->method('beginTransaction')->willReturn(true);
        DeviceTemplateTransaction::begin($db, ['poller_id' => 1], ['settings_user']);
        self::assertSame(array_map(static fn(string $table): string => 'SHOW CREATE TABLE `' . $table . '`', [...DeviceTemplateTransaction::AUTHORIZATION_TABLES, 'settings_user']), $inspected);
    }

    public function testNontransactionalAndMisleadingStorageNeverStartsATransaction(): void
    {
        foreach (["CREATE TEMPORARY TABLE `settings` (\n `id` int\n) ENGINE=MyISAM", "CREATE TABLE `settings` (\n `note` text COMMENT 'ENGINE=InnoDB'\n) ENGINE=MEMORY", 'CREATE VIEW settings AS SELECT 1', false] as $ddl) {
            $db = $this->createMock(\PDO::class);
            $db->method('inTransaction')->willReturn(false);
            $db->method('getAttribute')->willReturn('mysql');
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('fetch')->willReturn(['settings', $ddl]);
            $db->method('query')->with('SHOW CREATE TABLE `settings`')->willReturn($statement);
            $db->expects(self::never())->method('exec');
            $db->expects(self::never())->method('beginTransaction');
            try {
                DeviceTemplateTransaction::begin($db, ['poller_id' => 1], ['settings_user']);
                self::fail('Nontransactional storage accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Nontransactional storage.', $error->getMessage());
            }
        }
    }
}
