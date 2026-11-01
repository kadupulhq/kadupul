<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\ConfiguredDatabaseTarget;
use Kadupul\Platform\Infrastructure\Legacy\DatabaseFingerprint;
use Kadupul\Platform\Infrastructure\Legacy\NativeDatabaseTarget;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/RealMariaDb.php';

final class DatabaseTargetNativeTest extends TestCase
{
    use Fixtures\RealMariaDb;

    public function testSelectedSchemaAndNativeRoleMustMatchTheServerCapturedTarget(): void
    {
        $connection = $this->realMariaDb();
        $params = $connection->getParams();
        $adminUser = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER');
        $adminPassword = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD');
        if ($adminUser !== false && $adminUser !== '') $params['user'] = $adminUser;
        if ($adminPassword !== false) $params['password'] = $adminPassword;
        $administrator = \Doctrine\DBAL\DriverManager::getConnection($params);
        $created = [];
        $schemas = ['group_target_' . bin2hex(random_bytes(8)), 'group_target_' . bin2hex(random_bytes(8))];
        $keys = ['database_hostname', 'database_port', 'database_default', 'database_sessions', 'config'];
        $previous = array_intersect_key($GLOBALS, array_flip($keys));
        try {
            foreach ($schemas as $schema) {
                $administrator->executeStatement('CREATE DATABASE `' . $schema . '`');
                $created[] = $schema;
            }
            $database = new \PDO(
                'mysql:host=' . $params['host'] . ';port=' . $params['port'] . ';dbname=' . $schemas[0] . ';charset=utf8mb4',
                $params['user'],
                $params['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $values = ['collector_id' => 2, 'connection_status' => 'online', 'host' => $params['host'], 'port' => (int) $params['port']];
            $configuration = $this->createStub(LegacyConfiguration::class);
            $configuration->method('values')->willReturn($values);
            $connection = $this->createStub(DatabaseConnection::class);
            $connection->method('get')->willReturn($database);
            $target = (new ConfiguredDatabaseTarget($configuration, $connection))->capture();
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $target);
            $this->assertSame(hash('sha256', json_encode([2, 'online', strtolower($params['host']), (int) $params['port'], $schemas[0]], JSON_THROW_ON_ERROR)), $target);
            $this->assertSame($target, DatabaseFingerprint::capture($database, array_replace(
                $values,
                ['collector_id' => '02', 'host' => strtoupper($values['host']), 'port' => '0' . $values['port']]
            )));
            foreach (['offline', 'recovery'] as $state) {
                $this->assertNotSame($target, DatabaseFingerprint::capture($database, array_replace($values, ['connection_status' => $state])));
            }
            foreach ([['collector_id' => 3], ['host' => 'another.example'], ['port' => 1]] as $change) {
                $this->assertNotSame($target, DatabaseFingerprint::capture($database, array_replace($values, $change)));
            }
            $GLOBALS['database_hostname'] = $values['host'];
            $GLOBALS['database_port'] = $values['port'];
            $GLOBALS['database_default'] = $schemas[0];
            $GLOBALS['database_sessions'] = [$values['host'] . ':' . $values['port'] . ':' . $schemas[0] => $database];
            $GLOBALS['config'] = ['poller_id' => 2, 'connection' => 'online'];
            $this->assertSame($database, (new NativeDatabaseTarget())->select($target));
            $GLOBALS['config']['connection'] = 'recovery';
            $this->assertNull((new NativeDatabaseTarget())->select($target));
            $GLOBALS['config']['connection'] = 'online';
            $database->exec('USE `' . $schemas[1] . '`');
            $this->assertNull((new NativeDatabaseTarget())->select($target));
            $secondTarget = (new ConfiguredDatabaseTarget($configuration, $connection))->capture();
            $this->assertNotSame($target, $secondTarget);
            $this->assertSame($database, (new NativeDatabaseTarget())->select($secondTarget));
            $database->exec('USE `' . $schemas[0] . '`');
            $this->assertSame($database, (new NativeDatabaseTarget())->select($target));
            $this->assertNull((new NativeDatabaseTarget())->select('malformed'));
            foreach ([['port' => '3306.5'], ['port' => '3e3'], ['port' => 65536], ['port' => []],
                ['collector_id' => '2e0'], ['collector_id' => 0], ['collector_id' => '4294967296'], ['connection_status' => 'unknown'], ['host' => "bad\0host"]] as $change) {
                try {
                    DatabaseFingerprint::capture($database, array_replace($values, $change));
                    $this->fail('Malformed target metadata was admitted.');
                } catch (\RuntimeException $error) {
                    $this->assertSame('The database target could not be confirmed.', $error->getMessage());
                }
            }
            foreach (['config' => false, 'database_sessions' => false, 'database_hostname' => null, 'database_port' => [], 'database_default' => null] as $key => $invalid) {
                $saved = $GLOBALS[$key];
                $GLOBALS[$key] = $invalid;
                $this->assertNull((new NativeDatabaseTarget())->select($target));
                $GLOBALS[$key] = $saved;
            }
            $saved = $GLOBALS['database_sessions'];
            $GLOBALS['database_sessions'] = [];
            $this->assertNull((new NativeDatabaseTarget())->select($target));
            $GLOBALS['database_sessions'] = $saved;
            $traceSetting = ini_set('zend.exception_ignore_args', '0');
            $this->assertNotFalse($traceSetting);
            try {
                try {
                    DatabaseFingerprint::capture($database, array_replace($values, ['port' => 0, 'password' => 'private-target-canary']));
                    $this->fail('Invalid target metadata was admitted.');
                } catch (\RuntimeException $error) {
                    $this->assertStringNotContainsString('private-target-canary', print_r($error->getTrace(), true));
                }
            } finally {
                ini_set('zend.exception_ignore_args', $traceSetting);
            }
            $connectionId = $database->query('SELECT CONNECTION_ID()')->fetchColumn();
            $this->assertTrue(is_int($connectionId) || is_string($connectionId));
            $this->assertMatchesRegularExpression('/\A[1-9][0-9]*\z/D', (string) $connectionId);
            $database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
            $administrator->executeStatement('KILL CONNECTION ' . $connectionId);
            try {
                DatabaseFingerprint::capture($database, $values);
                $this->fail('A failed actual schema read produced a usable target.');
            } catch (\RuntimeException $error) {
                $this->assertSame('The database schema could not be confirmed.', $error->getMessage());
            }
        } finally {
            foreach ($keys as $key) {
                if (array_key_exists($key, $previous)) {
                    $GLOBALS[$key] = $previous[$key];
                } else {
                    unset($GLOBALS[$key]);
                }
            }
            foreach ($created as $schema) {
                $administrator->executeStatement('DROP DATABASE IF EXISTS `' . $schema . '`');
            }
            $administrator->close();
        }
    }
}
