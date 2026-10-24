<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Kadupul\Monitoring\Application\Port\BindingRepository;
use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Domain\BindingStatus;
use Kadupul\Monitoring\Domain\InvalidBinding;
use Kadupul\Monitoring\Domain\OwnershipClaimRefused;
use Kadupul\Monitoring\Domain\WriterOwnership;
use Kadupul\Monitoring\Infrastructure\Persistence\DbalBindingRepository;
use Kadupul\Monitoring\Infrastructure\Persistence\DbalWriterClaimStore;
use Kadupul\Monitoring\Infrastructure\Persistence\StoredBindingUnreadable;
use Kadupul\Monitoring\Infrastructure\Persistence\WriterOwnershipRegistry;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;
use Kadupul\Tests\Monitoring\Fakes\FixedWriterOwnershipProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The binding and claim stores against MariaDB or MySQL, on tables created
 * from cacti.sql. SQLite would not show the varbinary keys, row locks or
 * unique-key races this depends on, so without a server the tests skip.
 */
final class DbalMonitoringStoreDatabaseTest extends TestCase
{
    use BindingRepositoryContract;

    private const array TABLES = ['kadupul_metric_binding', 'kadupul_writer_ownership'];

    private Connection $database;
    /** @var list<Connection> */
    private array $others = [];

    protected function setUp(): void
    {
        $this->database = self::connect();
        foreach (self::TABLES as $table) {
            $this->database->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
        $sql = file_get_contents(dirname(__DIR__, 3) . '/cacti.sql');
        self::assertIsString($sql);
        foreach (self::TABLES as $table) {
            self::assertSame(1, preg_match('/^CREATE TABLE ' . $table . ' \(.*?^\) [^;\n]*;/ms', $sql, $create));
            $this->database->executeStatement(rtrim($create[0], ';'));
        }
    }

    protected function tearDown(): void
    {
        foreach ([...$this->others, $this->database ?? null] as $connection) {
            if ($connection instanceof Connection) {
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
        }
        if (isset($this->database)) {
            foreach (self::TABLES as $table) {
                $this->database->executeStatement('DROP TABLE IF EXISTS ' . $table);
            }
            $this->database->close();
        }
        foreach ($this->others as $connection) {
            $connection->close();
        }
    }

    protected function repository(): BindingRepository
    {
        return new DbalBindingRepository($this->database);
    }

    public function testOneRowPerKeyHoldsThePackOnlyAsDefinition(): void
    {
        $bindings = $this->repository();
        $bindings->save(BindingFixture::binding()->withStatus(BindingStatus::Approved));
        $bindings->save(BindingFixture::binding()->withStatus(BindingStatus::Approved)->withStatus(BindingStatus::Verified));

        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM kadupul_metric_binding'));
        self::assertSame(
            ['42', 'interface.in_octets', 'interface', 'eth0', 'snmp', 'openconfig-interfaces', '1.0.0', '7', 'traffic_in', 'COUNTER', 'verified', 'legacy_discovered', '2026-10-23 14:05:00.000000', null],
            array_map(static fn(mixed $v): ?string => $v === null ? null : (string) $v, array_values($this->database->fetchAssociative(
                'SELECT device_id, metric, resource_kind, resource_identity, protocol, pack_name, pack_version, local_data_id, data_source_name, expected_type, status, origin, recorded_at, last_verified_at FROM kadupul_metric_binding',
            ) ?: [])),
        );
        try {
            $this->database->executeStatement("INSERT INTO kadupul_metric_binding SELECT device_id, metric, resource_kind, resource_identity, protocol, 'other-pack', '2.0.0', 8, data_source_name, expected_type, 'proposed', origin, source_detail, recorded_at, NULL FROM kadupul_metric_binding");
            self::fail('The table accepted a second row for one binding key.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM kadupul_metric_binding'));
        }
    }

    public function testAValueTooLongForItsColumnIsRefusedBeforeAnyWrite(): void
    {
        $long = BindingFixture::binding(key: new BindingKey(42, new MetricId('interface.in_octets'), new ResourceId('interface', str_repeat('é', 128)), 'snmp'));

        try {
            $this->repository()->save($long);
            self::fail('A 256-byte resource identity was accepted.');
        } catch (InvalidBinding $e) {
            self::assertStringContainsString('resource identity of 256 bytes', $e->getMessage());
        }
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM kadupul_metric_binding'));
    }

    public function testACallerRollbackLeavesNoRows(): void
    {
        $registry = new WriterOwnershipRegistry(new FixedWriterOwnershipProbe(), new DbalWriterClaimStore($this->database, new MockClock()));
        $this->database->beginTransaction();
        $this->repository()->save(BindingFixture::binding());
        $registry->claim(BindingFixture::field());
        $this->database->rollBack();

        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM kadupul_metric_binding'));
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM kadupul_writer_ownership'));
        self::assertSame(WriterOwnership::Unassigned, $registry->ownership(BindingFixture::field()));
    }

    public function testDatabaseErrorsReachTheCaller(): void
    {
        $this->database->executeStatement('DROP TABLE kadupul_metric_binding');
        foreach ([fn() => $this->repository()->matching(BindingFixture::key()), fn() => $this->repository()->save(BindingFixture::binding())] as $call) {
            try {
                $call();
                self::fail('A missing table was not reported.');
            } catch (TableNotFoundException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testARowChangedOutsideTheStoreIsReportedNotGuessed(): void
    {
        $this->repository()->save(BindingFixture::binding());
        $this->database->executeStatement("UPDATE kadupul_metric_binding SET status = 'enabled'");

        $this->expectException(StoredBindingUnreadable::class);
        $this->expectExceptionMessage('device 42');
        $this->repository()->matching(BindingFixture::key());
    }

    public function testWithoutAClaimRowTheLegacyPollerKeepsTheField(): void
    {
        $legacy = new FixedWriterOwnershipProbe([BindingFixture::field()->toString() => WriterOwnership::LegacyPoller]);
        $registry = new WriterOwnershipRegistry($legacy, new DbalWriterClaimStore($this->database, new MockClock()));

        self::assertSame(WriterOwnership::LegacyPoller, $registry->ownership(BindingFixture::field()));
        try {
            $registry->claim(BindingFixture::field());
            self::fail('Claimed a legacy field.');
        } catch (OwnershipClaimRefused) {
            self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM kadupul_writer_ownership'));
        }
    }

    public function testAClaimIsOneRowThatSurvivesTheConnection(): void
    {
        $clock = new MockClock('2026-10-23 14:05:06.123456', 'UTC');
        $field = BindingFixture::field(9, 'traffic_in');
        $registry = new WriterOwnershipRegistry(new FixedWriterOwnershipProbe(), new DbalWriterClaimStore($this->database, $clock));

        self::assertSame(WriterOwnership::ModelDriven, $registry->claim($field));
        self::assertSame(WriterOwnership::ModelDriven, $registry->claim($field));
        self::assertSame([['9', 'traffic_in', 'model_driven', '2026-10-23 14:05:06.123456']], array_map(
            static fn(array $row): array => array_map('strval', array_values($row)),
            $this->database->fetchAllAssociative('SELECT local_data_id, data_source_name, writer, claimed_at FROM kadupul_writer_ownership'),
        ));

        $fresh = new WriterOwnershipRegistry(new FixedWriterOwnershipProbe(), new DbalWriterClaimStore($this->other(), $clock));
        self::assertSame(WriterOwnership::ModelDriven, $fresh->ownership($field));
        self::assertSame(WriterOwnership::Unassigned, $fresh->ownership(BindingFixture::field(9, 'Traffic_in')), 'data-source names are case sensitive');
        self::assertCount(1, $fresh->claims());
        self::assertTrue($fresh->claims()[0]->equals($field));
    }

    public function testTwoWritersRacingForAFieldLeaveOneClaim(): void
    {
        $field = BindingFixture::field(9, 'traffic_in');
        $first = new DbalWriterClaimStore($this->database, new MockClock());
        $second = $this->other();
        $second->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        $this->database->beginTransaction();
        self::assertTrue($first->record($field));
        try {
            (new DbalWriterClaimStore($second, new MockClock()))->record($field);
            self::fail('A second writer claimed a field held by an uncommitted claim.');
        } catch (LockWaitTimeoutException) {
            self::addToAssertionCount(1);
        }
        $this->database->commit();

        self::assertFalse((new DbalWriterClaimStore($second, new MockClock()))->record($field), 'the losing writer sees the committed claim');
        self::assertSame(1, (int) $this->database->fetchOne('SELECT COUNT(*) FROM kadupul_writer_ownership'));
    }

    public function testAClaimRowNamingAnotherWriterIsReportedNotTrusted(): void
    {
        $this->database->executeStatement("INSERT INTO kadupul_writer_ownership VALUES (9, 'traffic_in', 'legacy_poller', '2026-10-23 00:00:00')");
        $registry = new WriterOwnershipRegistry(new FixedWriterOwnershipProbe(), new DbalWriterClaimStore($this->database, new MockClock()));

        $this->expectException(StoredBindingUnreadable::class);
        $this->expectExceptionMessage('legacy_poller');
        $registry->ownership(BindingFixture::field(9, 'traffic_in'));
    }

    private static function connect(): Connection
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a task-owned MariaDB or MySQL database; these store tests were not run.');
        }
        $parameters = ['driver' => 'pdo_mysql', 'user' => getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', 'password' => getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', 'charset' => 'utf8mb4'];
        foreach (explode(';', (string) preg_replace('/^mysql:/', '', $dsn)) as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $mapped = ['host' => 'host', 'port' => 'port', 'dbname' => 'dbname', 'unix_socket' => 'unix_socket'][$name] ?? null;
            if ($mapped !== null) {
                $parameters[$mapped] = $mapped === 'port' ? (int) $value : $value;
            }
        }

        return DriverManager::getConnection($parameters);
    }

    private function other(): Connection
    {
        return $this->others[] = self::connect();
    }
}
