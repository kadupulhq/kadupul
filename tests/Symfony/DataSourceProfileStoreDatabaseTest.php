<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Graphing\Application\Port\DataSourceProfileAccess;
use Kadupul\Graphing\Infrastructure\Persistence\DbalDataSourceProfileStore;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The profile store on MySQL or MariaDB with the installed reference guards,
 * in a schema of its own. A queued write that resumes after a deletion
 * commits must not recreate the profile or any of its definitions.
 */
final class DataSourceProfileStoreDatabaseTest extends TestCase
{
    private const array TABLES = ['data_source_profiles', 'data_source_profiles_rra', 'data_source_profiles_cf', 'data_template_data', 'data_template_rrd',
        'settings', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm'];

    private ?PDO $administrator = null;
    private string $schema = '';
    /** @var array<string, mixed> */
    private array $parameters = [];
    private Connection $database;
    private DbalDataSourceProfileStore $store;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!$dsn) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
        require_once dirname(__DIR__, 2) . '/lib/data_source_profile_integrity.php';
        $this->administrator = new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'), getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->schema = 'profile_store_' . bin2hex(random_bytes(8));
        $this->administrator->exec('CREATE DATABASE `' . $this->schema . '`');
        $this->administrator->exec('USE `' . $this->schema . '`');
        $schema = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($schema);
        foreach (self::TABLES as $table) {
            self::assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`? \(.*?\n\) ENGINE=[^;]*;/s', $schema, $match), $table);
            $this->administrator->exec($match[0]);
        }
        foreach (\data_source_profile_reference_triggers() as $definition) {
            $this->administrator->exec($definition['sql']);
        }
        preg_match_all('/(\w+)=([^;]*)/', substr($dsn, strlen('mysql:')), $pairs, PREG_SET_ORDER);
        $options = array_column($pairs, 2, 1);
        $this->parameters = ['driver' => 'pdo_mysql', 'host' => $options['host'] ?? '127.0.0.1', 'port' => (int) ($options['port'] ?? 3306), 'dbname' => $this->schema,
            'user' => getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'),
            'password' => getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''), 'charset' => 'utf8mb4'];
        $this->database = DriverManager::getConnection($this->parameters);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1]);
        $this->store = new DbalDataSourceProfileStore($this->database, $this->createMock(DataSourceProfileAccess::class), $this->createMock(AuditTrail::class), $configuration);
        $this->database->executeStatement("INSERT INTO data_source_profiles (id, hash, name, step, heartbeat, x_files_factor, `default`) VALUES (1, 'd62c52891f4f9688729a5bc9fad91b18', 'Default', 300, 600, 0.5, 'on'), (3, 'ghi66d35da8f75c912ede3dbe901feda', 'Unused', 300, 600, 0.5, '')");
        $this->database->executeStatement("INSERT INTO data_source_profiles_rra (id, data_source_profile_id, name, steps, `rows`, timespan) VALUES (11, 1, 'Daily', 1, 600, 86400), (13, 3, 'Hourly', 1, 100, 30000)");
        $this->database->executeStatement('INSERT INTO data_source_profiles_cf VALUES (1, 1), (3, 1)');
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
        if ($this->administrator !== null && $this->schema !== '') {
            $this->administrator->exec('DROP DATABASE IF EXISTS `' . $this->schema . '`');
        }
    }

    public function testMysqlWritesPersistPropagateAndDelete(): void
    {
        $this->database->executeStatement('INSERT INTO data_template_data (local_data_id, data_template_id, data_source_profile_id) VALUES (0, 4, 1), (8, 4, 1)');
        $this->database->executeStatement('INSERT INTO data_template_rrd (local_data_id, data_template_id, rrd_heartbeat, data_source_name) VALUES (0, 4, 600, \'a\'), (8, 4, 600, \'a\'), (9, 4, 600, \'a\')');
        $saved = $this->store->save(7, 1, 'Default', null, 1200, null, [], false, $this->store->find(1)->revision);
        self::assertTrue($saved['retune']);
        self::assertSame(['1200', '1200', '600'], array_map('strval', $this->database->fetchFirstColumn('SELECT rrd_heartbeat FROM data_template_rrd ORDER BY id')));
        try {
            $this->store->save(7, 1, 'Default', 60, null, null, [], false, $this->store->find(1)->revision);
            self::fail('The step of a profile used by data sources changed.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('read only', $error->getMessage());
        }

        $this->store->duplicate(7, [3], [3 => $this->store->find(3)->revision], 'Copy of <profile_title>');
        $copy = (int) $this->database->fetchOne("SELECT id FROM data_source_profiles WHERE name = 'Copy of Unused'");
        self::assertSame([[1, 100, 30000]], array_map(static fn($archive): array => [$archive->steps, $archive->rows, $archive->timespan], $this->store->find($copy)->archives));
        $this->store->delete(7, [3, $copy], [3 => $this->store->find(3)->revision, $copy => $this->store->find($copy)->revision]);
        self::assertSame([1], array_map('intval', $this->database->fetchFirstColumn('SELECT id FROM data_source_profiles')));
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM data_source_profiles_rra WHERE data_source_profile_id <> 1'));
        $this->expectExceptionMessage('default Data Source Profile');
        $this->store->delete(7, [1], [1 => $this->store->find(1)->revision]);
    }

    public function testWritesFailClosedWithoutIntactGuardsOrTransactionalTables(): void
    {
        $definitions = \data_source_profile_reference_triggers();
        $name = array_key_first($definitions);
        $this->administrator->exec("DROP TRIGGER `$name`");
        $this->assertRefused('guards are missing');
        $this->administrator->exec("CREATE TRIGGER `$name` AFTER INSERT ON data_template_data FOR EACH ROW BEGIN END");
        $this->assertRefused('guards are modified');
        $this->administrator->exec("DROP TRIGGER `$name`");
        $this->administrator->exec($definitions[$name]['sql']);
        $this->administrator->exec('ALTER TABLE data_source_profiles_cf ENGINE=MyISAM');
        $this->assertRefused('transactional tables');
        $this->administrator->exec('ALTER TABLE data_source_profiles_cf ENGINE=InnoDB');
        self::assertGreaterThan(3, $this->store->save(7, null, 'Restored', 300, 600, '0.5', [1], false, null)['id']);
    }

    /** @return array<string, array{string, string}> */
    public static function queuedWrites(): array
    {
        $cases = [];
        foreach (['commit', 'rollback'] as $outcome) {
            foreach (['save', 'rra', 'copy', 'remove'] as $operation) {
                $cases[$outcome . ' ' . $operation] = [$outcome, $operation];
            }
        }
        return $cases;
    }

    #[DataProvider('queuedWrites')]
    public function testAQueuedWriteCannotResurrectADeletedProfile(string $outcome, string $operation): void
    {
        $profile = $this->store->find(3);
        $deleter = DriverManager::getConnection($this->parameters);
        $deleter->beginTransaction();
        $deleter->fetchAllAssociative('SELECT id FROM data_source_profiles WHERE id = 3 FOR UPDATE');
        $deleter->fetchAllAssociative('SELECT data_source_profile_id FROM data_template_data WHERE data_source_profile_id = 3 FOR UPDATE');
        $deleter->executeStatement('DELETE FROM data_source_profiles_cf WHERE data_source_profile_id = 3');
        $deleter->executeStatement('DELETE FROM data_source_profiles_rra WHERE data_source_profile_id = 3');
        $deleter->executeStatement('DELETE FROM data_source_profiles WHERE id = 3');
        $request = json_encode(['connection' => $this->parameters, 'operation' => $operation, 'revision' => $profile->revision, 'archive' => $profile->archives[0]->revision], JSON_THROW_ON_ERROR);
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Fixtures/data-source-profile-store-native.php', $request], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            $writer = (int) fgets($pipes[1]);
            self::assertTrue($this->waitsFor($writer, (int) $deleter->fetchOne('SELECT CONNECTION_ID()')), 'The queued write did not wait for the deletion.');
            $outcome === 'commit' ? $deleter->commit() : $deleter->rollBack();
            $result = json_decode((string) stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('', stream_get_contents($pipes[2]));
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $deleter->close();
        }
        if ($outcome === 'commit') {
            self::assertSame(['ok' => false, 'error' => \InvalidArgumentException::class, 'message' => 'Data Source Profile not found.'], $result);
            self::assertSame([1], array_map('intval', $this->database->fetchFirstColumn('SELECT id FROM data_source_profiles')));
            foreach (['data_source_profiles_rra', 'data_source_profiles_cf'] as $table) {
                self::assertSame(['1'], array_map('strval', $this->database->fetchFirstColumn("SELECT DISTINCT data_source_profile_id FROM $table")), $table);
            }
            return;
        }
        self::assertTrue($result['ok'], json_encode($result));
        $expected = match ($operation) {
            'save' => 'changed',
            default => 'Unused',
        };
        self::assertSame($expected, $this->database->fetchOne('SELECT name FROM data_source_profiles WHERE id = 3'));
        self::assertSame(match ($operation) {
            'rra' => 2,
            'remove' => 0,
            default => 1,
        }, (int) $this->database->fetchOne('SELECT COUNT(*) FROM data_source_profiles_rra WHERE data_source_profile_id = 3'));
        self::assertSame($operation === 'copy' ? 3 : 2, (int) $this->database->fetchOne('SELECT COUNT(*) FROM data_source_profiles'));
    }

    private function assertRefused(string $message): void
    {
        try {
            $this->store->save(7, null, 'Refused', 300, 600, '0.5', [1], false, null);
            self::fail('A profile write ran without intact guards and InnoDB tables.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
        self::assertSame(0, (int) $this->database->fetchOne("SELECT COUNT(*) FROM data_source_profiles WHERE name = 'Refused'"));
    }

    /** Watch the server's lock graph instead of sleeping, as the reference race contract does. */
    private function waitsFor(int $writer, int $holder): bool
    {
        $version = (string) $this->administrator->query('SELECT VERSION()')->fetchColumn();
        $statement = $this->administrator->prepare(str_contains($version, 'MariaDB')
            ? 'SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w
                JOIN information_schema.INNODB_TRX requester ON requester.trx_id = w.requesting_trx_id
                JOIN information_schema.INNODB_TRX blocker ON blocker.trx_id = w.blocking_trx_id
                WHERE requester.trx_mysql_thread_id = ? AND blocker.trx_mysql_thread_id = ?'
            : 'SELECT COUNT(*) FROM performance_schema.data_lock_waits w
                JOIN performance_schema.threads requester ON requester.THREAD_ID = w.REQUESTING_THREAD_ID
                JOIN performance_schema.threads blocker ON blocker.THREAD_ID = w.BLOCKING_THREAD_ID
                WHERE requester.PROCESSLIST_ID = ? AND blocker.PROCESSLIST_ID = ?');
        $deadline = microtime(true) + 20;
        do {
            $statement->execute([$writer, $holder]);
            if ((int) $statement->fetchColumn() > 0) {
                return true;
            }
            $statement->closeCursor();
            // MariaDB refreshes its InnoDB metadata snapshot every 100 ms.
            usleep(250000);
        } while (microtime(true) < $deadline);
        return false;
    }
}
