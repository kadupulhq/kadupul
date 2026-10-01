<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Legacy\DeviceTemplateTransaction;
use PHPUnit\Framework\TestCase;
use Kadupul\Tests\Fixtures\RealMariaDb;

final class DeviceTemplateTransactionTest extends TestCase
{
    use RealMariaDb;

    public function testSilentMariaDbPreferenceWriteFailureCannotCommitSuccess(): void
    {
        $connection = $this->realMariaDb();
        $db = $connection->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $db);
        $tables = [
            'settings' => 'name VARCHAR(128) PRIMARY KEY, value TEXT',
            'user_auth' => 'id INT PRIMARY KEY, username VARCHAR(50), enabled CHAR(2), locked CHAR(3), must_change_password CHAR(2)',
            'user_auth_realm' => 'user_id INT, realm_id INT, PRIMARY KEY(user_id,realm_id)',
            'user_auth_group' => 'id INT PRIMARY KEY, enabled CHAR(2)',
            'user_auth_group_members' => 'group_id INT, user_id INT, PRIMARY KEY(group_id,user_id)',
            'user_auth_group_realm' => 'group_id INT, realm_id INT, PRIMARY KEY(group_id,realm_id)',
            'settings_user' => 'user_id INT, name VARCHAR(128), value TEXT, PRIMARY KEY(user_id,name)',
        ];
        try {
            foreach ($tables as $table => $columns) {
                $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
                $db->exec('CREATE TABLE `' . $table . '` (' . $columns . ') ENGINE=InnoDB');
            }
            $db->exec("INSERT INTO settings VALUES ('auth_method','1'),('guest_user','0')");
            $db->exec("INSERT INTO user_auth VALUES (42,'operator','on','','')");
            $db->exec('INSERT INTO user_auth_realm VALUES (42,8),(42,12)');
            $db->exec("CREATE TRIGGER device_template_silent_write BEFORE INSERT ON settings_user FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected silent preference failure'");
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
            $database = $this->createMock(\Kadupul\Platform\Contract\DatabaseConnection::class);
            $database->method('get')->willReturn($db);
            $configuration = $this->createMock(\Kadupul\Platform\Contract\LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['collector_id' => 1]);
            $adapter = new \Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceTemplateDefinitions($database, dirname(__DIR__, 2), $configuration);
            try {
                $adapter->remember(42, ['q' => 'must-not-persist']);
                self::fail('Silent write failure accepted as a committed preference.');
            } catch (\RuntimeException $error) {
                self::assertSame('Device template database operation was not confirmed.', $error->getMessage());
            }
            self::assertFalse($db->inTransaction());
            self::assertSame(0, (int) $db->query('SELECT COUNT(*) FROM settings_user')->fetchColumn());
        } finally {
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            if ($db->inTransaction()) $db->rollBack();
            foreach (array_reverse(array_keys($tables)) as $table) {
                $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
            }
            $connection->close();
        }
    }
    public function testSilentMariaDbChildReadFailureCannotProduceRevision(): void
    {
        $connection = $this->realMariaDb();
        $db = $connection->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $db);
        $tables = ['host_template', 'host_template_graph', 'host_template_snmp_query'];
        try {
            foreach ($tables as $table) $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
            $db->exec('CREATE TABLE host_template (id INT PRIMARY KEY, name VARCHAR(100), class VARCHAR(32)) ENGINE=InnoDB');
            $db->exec('CREATE TABLE host_template_snmp_query (host_template_id INT, snmp_query_id INT) ENGINE=InnoDB');
            $db->exec("INSERT INTO host_template VALUES (7,'parent','router')");
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
            $db->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Device template database operation was not confirmed.');
            \Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceTemplateDefinitions::read($db, 7);
        } finally {
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            foreach ($tables as $table) $db->exec('DROP TABLE IF EXISTS `' . $table . '`');
            $connection->close();
        }
    }
    public function testActualInstallationConfigurationUsesNormalizedCollectorIdentity(): void
    {
        $directory = sys_get_temp_dir() . '/device-template-configuration-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        file_put_contents($directory . '/include/config.php', '<?php $poller_id = 1; $database_default = "fixture";');
        try {
            $configuration = new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration($directory);
            $values = $configuration->values();
            self::assertSame(1, $values['collector_id']);
            self::assertArrayNotHasKey('poller_id', $values);
            $db = $this->createMock(\PDO::class);
            $db->method('inTransaction')->willReturn(false);
            $db->method('getAttribute')->willReturn('mysql');
            $db->method('errorCode')->willReturn('00000');
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('errorCode')->willReturn('00000');
            $statement->method('fetch')->willReturn(['table', "CREATE TABLE `table` (\n`id` int\n) ENGINE=InnoDB"]);
            $db->expects(self::exactly(count(DeviceTemplateTransaction::AUTHORIZATION_TABLES)))->method('query')->willReturn($statement);
            $db->expects(self::once())->method('exec')->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->willReturn(0);
            $db->expects(self::once())->method('beginTransaction')->willReturn(true);
            DeviceTemplateTransaction::begin($db, $values, []);
        } finally {
            unlink($directory . '/include/config.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }
    public function testInvalidNormalizedCollectorIdentitiesNeverInspectOrBegin(): void
    {
        foreach ([[], ['poller_id' => 1], ['collector_id' => false], ['collector_id' => '1x'], ['collector_id' => 2]] as $configuration) {
            $db = $this->createMock(\PDO::class);
            $db->method('inTransaction')->willReturn(false);
            $db->method('getAttribute')->willReturn('mysql');
            $db->method('errorCode')->willReturn('00000');
            foreach (['query', 'exec', 'beginTransaction'] as $method) {
                $db->expects(self::never())->method($method);
            }
            try {
                DeviceTemplateTransaction::begin($db, $configuration, []);
                self::fail('Invalid normalized collector accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Device templates require the primary collector.', $error->getMessage());
            }
        }
    }
    public function testCallerTransactionRemainsActiveAndItsWritesSurviveRejection(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE preserved (value TEXT)');
        $db->beginTransaction();
        $db->exec("INSERT INTO preserved VALUES ('caller')");
        try {
            DeviceTemplateTransaction::begin($db, ['collector_id' => 1], ['settings_user']);
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
        $configuration->method('values')->willReturn(['collector_id' => 1]);
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
            $db->method('errorCode')->willReturn('00000');
            if ($failure === 'inspection') {
                $db->expects(self::once())->method('query')->with('SHOW CREATE TABLE `settings`')->willReturn(false);
                $db->expects(self::never())->method('exec');
            } else {
                $statement = $this->createMock(\PDOStatement::class);
                $statement->method('errorCode')->willReturn('00000');
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
            $configuration->method('values')->willReturn(['collector_id' => 1]);
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
        DeviceTemplateTransaction::begin($db, ['collector_id' => 2], ['settings_user']);
    }

    public function testStorageChecksTheActualConnectionTableAndAllAuthorizationDependencies(): void
    {
        $db = $this->createMock(\PDO::class);
        $db->method('inTransaction')->willReturn(false);
        $db->method('getAttribute')->willReturn('mysql');
        $db->method('errorCode')->willReturn('00000');
        $inspected = [];
        $db->method('query')->willReturnCallback(function (string $sql) use (&$inspected): \PDOStatement {
            $inspected[] = $sql;
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('errorCode')->willReturn('00000');
            $statement->method('fetch')->willReturn(['table', "CREATE TABLE `table` (\n `id` int\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"]);
            return $statement;
        });
        $db->expects(self::once())->method('exec')->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->willReturn(0);
        $db->expects(self::once())->method('beginTransaction')->willReturn(true);
        DeviceTemplateTransaction::begin($db, ['collector_id' => 1], ['settings_user']);
        self::assertSame(array_map(static fn(string $table): string => 'SHOW CREATE TABLE `' . $table . '`', [...DeviceTemplateTransaction::AUTHORIZATION_TABLES, 'settings_user']), $inspected);
    }

    public function testNontransactionalAndMisleadingStorageNeverStartsATransaction(): void
    {
        foreach (["CREATE TEMPORARY TABLE `settings` (\n `id` int\n) ENGINE=MyISAM", "CREATE TABLE `settings` (\n `note` text COMMENT 'ENGINE=InnoDB'\n) ENGINE=MEMORY", 'CREATE VIEW settings AS SELECT 1', false] as $ddl) {
            $db = $this->createMock(\PDO::class);
            $db->method('inTransaction')->willReturn(false);
            $db->method('getAttribute')->willReturn('mysql');
            $db->method('errorCode')->willReturn('00000');
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('errorCode')->willReturn('00000');
            $statement->method('fetch')->willReturn(['settings', $ddl]);
            $db->method('query')->with('SHOW CREATE TABLE `settings`')->willReturn($statement);
            $db->expects(self::never())->method('exec');
            $db->expects(self::never())->method('beginTransaction');
            try {
                DeviceTemplateTransaction::begin($db, ['collector_id' => 1], ['settings_user']);
                self::fail('Nontransactional storage accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Nontransactional storage.', $error->getMessage());
            }
        }
    }
}
