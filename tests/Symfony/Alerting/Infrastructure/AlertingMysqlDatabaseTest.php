<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Alerting\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Alerting\Application\Port\RunLock;
use Kadupul\Alerting\Application\Port\SeriesObservation;
use Kadupul\Alerting\Domain\Evaluation\Observation;
use Kadupul\Alerting\Domain\Rule\HourBucket;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRunLock;
use Kadupul\Alerting\Infrastructure\Dbal\DsstatsBaselineSource;
use Kadupul\Alerting\Infrastructure\Dbal\DsstatsRateSource;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The alerting adapters whose SQL only MariaDB and MySQL run: the named run
 * locks, the dsstats rate read (UNIX_TIMESTAMP) and the baseline upsert (ON
 * DUPLICATE KEY UPDATE), on the shipped cacti.sql tables in a schema this
 * test owns and drops. Needs KADUPUL_TEST_MYSQL_DSN; the account must be
 * able to create databases and kill its own sessions.
 * tests/integration/Alert*DatabaseTest.php run the evaluator and the real
 * dsstats writer against the same tables.
 */
final class AlertingMysqlDatabaseTest extends TestCase
{
    private const array TABLES = ['data_source_stats_hourly_last', 'data_source_stats_hourly_cache', 'data_template_data', 'alert_baseline_sample'];
    private const string ZONE = 'America/New_York';
    private const int NOW = 1_800_000_000;

    private ?PDO $administrator = null;
    /** @var list<string> */
    private array $schemas = [];
    /** @var array<string, mixed> */
    private array $parameters = [];
    /** @var list<Connection> */
    private array $connections = [];
    private Connection $database;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!$dsn) {
            self::markTestSkipped('KADUPUL_TEST_MYSQL_DSN is required for database contracts');
        }
        $user = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root');
        $password = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '');
        $this->administrator = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        preg_match_all('/(\w+)=([^;]*)/', substr($dsn, strlen('mysql:')), $pairs, PREG_SET_ORDER);
        $options = array_column($pairs, 2, 1);
        $this->parameters = ['driver' => 'pdo_mysql', 'host' => $options['host'] ?? '127.0.0.1', 'port' => (int) ($options['port'] ?? 3306),
            'user' => $user, 'password' => $password, 'charset' => 'utf8mb4'];
        $this->database = $this->connect($this->schema());
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        foreach ($this->schemas as $schema) {
            $this->administrator?->exec('DROP DATABASE IF EXISTS `' . $schema . '`');
        }
    }

    public function testOneSessionHoldsEachRunLockUntilItReleasesIt(): void
    {
        $first = new DbalRunLock($this->database);
        $second = new DbalRunLock($this->connect($this->schemas[0]));

        self::assertTrue($first->acquire(RunLock::EVALUATE));
        self::assertFalse($second->acquire(RunLock::EVALUATE), 'Busy: another session holds it.');
        self::assertTrue($second->acquire(RunLock::DELIVER), 'Each purpose is its own lock.');
        self::assertFalse($second->release(RunLock::EVALUATE), 'Only the holder releases it.');
        self::assertFalse($first->acquire(RunLock::DELIVER));

        self::assertTrue($first->release(RunLock::EVALUATE));
        self::assertFalse($first->release(RunLock::EVALUATE), 'Nobody holds it any more.');
        self::assertTrue($second->acquire(RunLock::EVALUATE));
        self::assertTrue($second->release(RunLock::EVALUATE));
        self::assertTrue($second->release(RunLock::DELIVER));
    }

    public function testAnotherInstallationOnTheServerTakesItsOwnLock(): void
    {
        $here = new DbalRunLock($this->database);
        $there = new DbalRunLock($this->connect($this->schema()));

        self::assertTrue($here->acquire(RunLock::EVALUATE));
        self::assertTrue($there->acquire(RunLock::EVALUATE), 'The lock name carries the schema.');
        self::assertTrue($there->release(RunLock::EVALUATE));
        self::assertTrue($here->release(RunLock::EVALUATE));
    }

    public function testALostSessionDropsItsLockAndReleasingItReportsFalse(): void
    {
        $lost = new DbalRunLock($this->database);
        self::assertTrue($lost->acquire(RunLock::EVALUATE));
        $session = $this->database->fetchOne('SELECT CONNECTION_ID()');
        self::assertIsNumeric($session);
        $this->administrator?->exec('KILL ' . (int) $session);

        // The killed session cannot release; the server already did.
        self::assertFalse($lost->release(RunLock::EVALUATE));
        $next = new DbalRunLock($this->connect($this->schemas[0]));
        self::assertTrue($next->acquire(RunLock::EVALUATE));
        self::assertTrue($next->release(RunLock::EVALUATE));
    }

    public function testALockIsRefusedForAnUnknownPurposeOrWithoutASelectedSchema(): void
    {
        try {
            (new DbalRunLock($this->database))->acquire('poll');
            self::fail('An unknown lock was requested.');
        } catch (\InvalidArgumentException $unknown) {
            self::assertSame('Unknown alerting lock.', $unknown->getMessage());
        }

        $schemaless = $this->connect(null);
        try {
            (new DbalRunLock($schemaless))->acquire(RunLock::EVALUATE);
            self::fail('A lock was taken without a schema to name it by.');
        } catch (\RuntimeException $missing) {
            self::assertSame('The alerting lock needs a selected database.', $missing->getMessage());
        }
    }

    public function testTheRateIsTheCalculatedValueAtTheTimeTheWriterStoredIt(): void
    {
        $this->series(101, 'traffic_in', 300, 12.5, [[self::NOW - 300, 10.0], [self::NOW, 12.5]]);
        // The writer is between its two inserts: the newest cache value is not the calculated one.
        $this->series(102, 'traffic_in', 300, 7.0, [[self::NOW - 300, 7.0], [self::NOW, 9.0]]);
        // Stored as Traffic_In: the case-insensitive join finds it, the rule names another series.
        $this->series(103, 'Traffic_In', 300, 5.0, [[self::NOW, 5.0]]);
        // No polling interval, and no calculated rate yet.
        $this->series(104, 'traffic_in', 0, 3.0, [[self::NOW, 3.0]]);
        $this->series(105, 'traffic_in', 60, null, [[self::NOW, 1.0]]);
        $this->series(106, 'traffic_out', 60, 0.0, [[self::NOW - 60, 0.0]]);

        $keys = array_map(static fn(int $id): SeriesKey => new SeriesKey($id, 'traffic_in'), [101, 102, 103, 104, 105, 107]);
        $keys[] = new SeriesKey(106, 'traffic_out');

        self::assertEquals([
            '101:traffic_in' => new Observation(12.5, self::NOW, 300),
            '106:traffic_out' => new Observation(0.0, self::NOW - 60, 60),
        ], (new DsstatsRateSource($this->database))->currentRates($keys));
        self::assertSame([], (new DsstatsRateSource($this->database))->currentRates([]));
    }

    public function testARateTableOfAnotherShapeFailsTheReadInsteadOfReadingAsZero(): void
    {
        // A text column the shipped schema does not have, holding what no writer stores.
        $this->database->executeStatement('ALTER TABLE data_template_data MODIFY rrd_step varchar(16) NOT NULL');
        $this->series(101, 'traffic_in', '300 seconds', 12.5, [[self::NOW, 12.5]]);
        $this->assertUnexpectedRow();

        $this->database->executeStatement("UPDATE data_template_data SET rrd_step = '300'");
        $this->database->executeStatement('ALTER TABLE data_source_stats_hourly_last MODIFY calculated varchar(16) NULL');
        $this->database->executeStatement('ALTER TABLE data_source_stats_hourly_cache MODIFY value varchar(16) NULL');
        $this->database->executeStatement("UPDATE data_source_stats_hourly_last SET calculated = 'n/a'");
        $this->database->executeStatement("UPDATE data_source_stats_hourly_cache SET value = 'n/a'");
        $this->assertUnexpectedRow();
    }

    public function testTheBaselineUpsertAddsEachNewerSampleOnceAndKeepsTheNewestTime(): void
    {
        $series = new SeriesKey(7, 'traffic_in');
        $source = new DsstatsBaselineSource($this->database, new \DateTimeZone(self::ZONE));
        $nine = (new \DateTimeImmutable('2026-11-09 09:00:00', new \DateTimeZone(self::ZONE)))->getTimestamp();
        $seen = static fn(float $rate, int $at): SeriesObservation => new SeriesObservation($series, new Observation($rate, $at, 300));

        $source->record([$seen(10.0, $nine + 300)]);
        $source->record([$seen(20.0, $nine + 600)]);
        // Read again, and written late: neither is a new sample, and neither moves the time back.
        $source->record([$seen(20.0, $nine + 600)]);
        $source->record([$seen(99.0, $nine + 400)]);

        self::assertSame([['2', '30', '500', (string) ($nine + 600)]], array_map(
            static fn(array $row): array => array_map(static fn(mixed $value): string => (string) $value, $row),
            $this->database->fetchAllNumeric('SELECT sample_count, sample_sum, sample_sum_squares, last_observed_at FROM alert_baseline_sample'),
        ));
        // A week later the same hour of the week reads that bucket.
        self::assertEquals(['7:traffic_in' => [new HourBucket(2, 30.0, 500.0)]], $source->history([$seen(1.0, $nine + 7 * 86_400 + 60)]));
    }

    private function assertUnexpectedRow(): void
    {
        try {
            (new DsstatsRateSource($this->database))->currentRates([new SeriesKey(101, 'traffic_in')]);
            self::fail('A row of another shape was read.');
        } catch (\UnexpectedValueException $error) {
            self::assertSame('The dsstats tables returned an unexpected row.', $error->getMessage());
        }
    }

    /**
     * One data source as dsstats_poller_output() leaves it: its last
     * calculated rate and its hourly cache values at each time.
     *
     * @param list<array{int, float}> $cache
     */
    private function series(int $localDataId, string $rrdName, int|string $step, ?float $calculated, array $cache): void
    {
        $this->database->insert('data_template_data', ['local_data_id' => $localDataId, 'data_template_id' => 1, 'data_input_id' => 1, 'name' => 'ds', 'data_source_path' => '', 'rrd_step' => $step]);
        $this->database->insert('data_source_stats_hourly_last', ['local_data_id' => $localDataId, 'rrd_name' => $rrdName, 'value' => $calculated, 'calculated' => $calculated]);
        foreach ($cache as [$at, $value]) {
            $this->database->executeStatement('INSERT INTO data_source_stats_hourly_cache (local_data_id, rrd_name, time, value) VALUES (?, ?, FROM_UNIXTIME(?), ?)', [$localDataId, $rrdName, $at, $value]);
        }
    }

    /** A new schema with the shipped tables, dropped in tearDown(). */
    private function schema(): string
    {
        $schema = 'alerting_adapters_' . bin2hex(random_bytes(8));
        $this->administrator?->exec('CREATE DATABASE `' . $schema . '`');
        $this->schemas[] = $schema;
        $this->administrator?->exec('USE `' . $schema . '`');
        $sql = file_get_contents(dirname(__DIR__, 4) . '/cacti.sql');
        self::assertIsString($sql);
        foreach (self::TABLES as $table) {
            self::assertSame(1, preg_match('/^CREATE TABLE `?' . $table . '`? \(.*?\n\s*\) ENGINE=[^;]*;/ms', $sql, $match), $table);
            $this->administrator?->exec($match[0]);
        }

        return $schema;
    }

    private function connect(?string $schema): Connection
    {
        $parameters = $this->parameters;
        if ($schema !== null) {
            $parameters['dbname'] = $schema;
        }
        $connection = DriverManager::getConnection($parameters);
        // The time zone the poller and the dsstats writer share; UNIX_TIMESTAMP() must not depend on it.
        $connection->executeStatement("SET time_zone = '+05:30'");
        $this->connections[] = $connection;

        return $connection;
    }
}
