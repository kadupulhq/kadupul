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
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/Fixtures/RealMariaDb.php';
require_once __DIR__ . '/Fixtures/DatabaseTargetFaultPdo.php';

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
            $connection = \Kadupul\Platform\Infrastructure\Legacy\Dbal\LegacyDatabaseConnection::open($database);
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

    /** @return iterable<string,array{string,list<string>}> */
    public static function schemaReadStages(): iterable
    {
        foreach (['none', 'prepare-false', 'prepare-throw', 'execute-false', 'execute-throw', 'execute-state', 'execute-null-state', 'state-throw', 'fetch-state', 'fetch-null-state', 'fetch-throw', 'fetch-false', 'fetch-empty', 'fetch-integer'] as $stage) {
            $events = ['prepare'];
            if (!str_starts_with($stage, 'prepare-')) $events[] = 'execute';
            if (!str_starts_with($stage, 'prepare-') && !in_array($stage, ['execute-false', 'execute-throw'], true)) $events[] = 'errorCode';
            if ($stage === 'none' || str_starts_with($stage, 'fetch-')) {
                $events[] = 'fetchColumn';
                if ($stage !== 'fetch-throw') $events[] = 'errorCode';
            }
            yield $stage => [$stage, $events];
        }
    }

    /** @param list<string> $events */
    #[DataProvider('schemaReadStages')]
    public function testSchemaReadFailuresCannotProduceAnAdmittedTarget(string $stage, array $events): void
    {
        $parameters = $this->realMariaDb()->getParams();
        $adminUser = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER');
        $adminPassword = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD');
        if ($adminUser !== false && $adminUser !== '') $parameters['user'] = $adminUser;
        if ($adminPassword !== false) $parameters['password'] = $adminPassword;
        $database = new \PDO('mysql:host=' . $parameters['host'] . ';port=' . $parameters['port'], $parameters['user'], $parameters['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $schema = 'database_target_fault_' . bin2hex(random_bytes(8));
        $created = false;
        try {
            $database->exec('CREATE DATABASE `' . $schema . '`');
            $created = true;
            $database->exec('USE `' . $schema . '`');
            $this->assertSame($schema, $database->query('SELECT DATABASE()')->fetchColumn());
            $values = ['collector_id' => 2, 'connection_status' => 'online', 'host' => $parameters['host'], 'port' => $parameters['port']];
            $expected = DatabaseFingerprint::capture($database, $values);
            $fault = new Fixtures\DatabaseTargetFaultPdo($database, $stage);
            $target = null;
            $failure = null;
            try {
                $target = DatabaseFingerprint::capture($fault, $values);
            } catch (\RuntimeException $error) {
                $failure = $error;
            }
            if ($stage === 'none') {
                $this->assertNull($failure);
                $this->assertSame($expected, $target);
            } else {
                $this->assertInstanceOf(\RuntimeException::class, $failure, 'A failed schema read produced a usable fingerprint.');
                $this->assertNull($target);
            }
            $this->assertSame($events, $fault->events, 'The intended driver failure stage must be reached.');
        } finally {
            if ($created) $database->exec('DROP DATABASE `' . $schema . '`');
        }
    }


    public function testConfiguredTargetUsesTheActualWebConnection(): void
    {
        $params = $this->realMariaDb()->getParams();
        $user = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER');
        $password = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD');
        if ($user !== false && $user !== '') $params['user'] = $user;
        if ($password !== false) $params['password'] = $password;
        $administrator = new \PDO('mysql:host=' . $params['host'] . ';port=' . $params['port'], $params['user'], $params['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $schemas = ['web_target_' . bin2hex(random_bytes(8)), 'web_target_' . bin2hex(random_bytes(8))];
        $created = [];
        $directory = sys_get_temp_dir() . '/web-target-config-' . bin2hex(random_bytes(8));
        $ownedDirectory = false;
        $kernel = null;
        try {
            foreach ($schemas as $schema) {
                $administrator->exec('CREATE DATABASE `' . $schema . '`');
                $created[] = $schema;
            }
            $dsn = 'mysql:host=' . $params['host'] . ';port=' . $params['port'] . ';dbname=' . $schemas[0];
            $native = new \PDO($dsn, $params['user'], $params['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $web = new \PDO($dsn, $params['user'], $params['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $writer = new class ($native) implements DatabaseConnection {
                public int $calls = 0;
                public function __construct(private readonly \PDO $native) {}
                public function get(): \PDO
                {
                    ++$this->calls;
                    return $this->native;
                }
            };
            $this->assertTrue(mkdir($directory . '/include', 0700, true));
            $ownedDirectory = true;
            $configuration = "<?php\n";
            foreach (['database_hostname' => $params['host'], 'database_port' => $params['port'], 'database_default' => $schemas[0], 'database_username' => $params['user'], 'database_password' => $params['password']] as $key => $value) {
                $configuration .= '$' . $key . ' = ' . var_export($value, true) . ";\n";
            }
            $this->assertNotFalse(file_put_contents($directory . '/include/config.php', $configuration));
            $kernel = new \Kadupul\Kernel('test', true);
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $container->set(\Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration::class, new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration($directory));
            $container->set(DatabaseConnection::class, $writer);
            $container->set('doctrine.dbal.web_connection', \Kadupul\Platform\Infrastructure\Legacy\Dbal\LegacyDatabaseConnection::open($web));
            $target = $container->get('test.platform.database_target');
            $this->assertInstanceOf(\Kadupul\Platform\Contract\DatabaseTarget::class, $target);
            $initial = $target->capture();
            $web->exec('USE `' . $schemas[1] . '`');
            $this->assertSame($schemas[1], $web->query('SELECT DATABASE()')->fetchColumn());
            $this->assertSame($schemas[0], $native->query('SELECT DATABASE()')->fetchColumn());
            $this->assertNotSame($initial, $target->capture(), 'Target capture ignored the schema used by actual feature reads.');
            $this->assertSame(0, $writer->calls, 'Read target capture must not open the legacy credential connection.');
        } finally {
            $kernel?->shutdown();
            foreach ($created as $schema) $administrator->exec('DROP DATABASE `' . $schema . '`');
            if ($ownedDirectory) {
                unlink($directory . '/include/config.php');
                rmdir($directory . '/include');
                rmdir($directory);
            }
        }
    }


    public function testUnsupportedNativeReadConnectionsAreRefused(): void
    {
        $configuration = $this->createStub(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1, 'host' => 'localhost', 'port' => 3306]);
        $database = $this->createStub(\Doctrine\DBAL\Connection::class);
        $database->method('getNativeConnection')->willReturn(new \stdClass());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The database target could not be confirmed.');
        (new ConfiguredDatabaseTarget($configuration, $database))->capture();
    }

}
