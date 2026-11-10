<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Baseline rules on MariaDB or MySQL over the shipped cacti.sql tables, in a
 * schema this test owns and drops: the alert_baseline_sample upsert, whose
 * ON DUPLICATE KEY UPDATE assignments run left to right on these engines,
 * history written and read back by the evaluator across weeks and a daylight
 * saving change, and an unreadable baseline row in real InnoDB columns.
 */

namespace AlertBaselineDatabaseTest;

$applicationLoader = require dirname(__DIR__, 2) . '/include/vendor/autoload.php';
$applicationLoader->unregister();
$applicationLoader->register(false);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\Alerting\Application\EvaluateRules;
use Kadupul\Alerting\Application\EvaluationReport;
use Kadupul\Alerting\Application\Port\SeriesObservation;
use Kadupul\Alerting\Domain\Evaluation\Observation;
use Kadupul\Alerting\Domain\Rule\BaselineCalendar;
use Kadupul\Alerting\Domain\Rule\HourBucket;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Kadupul\Alerting\Infrastructure\Dbal\DbalEvaluationCursor;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentRepository;
use Kadupul\Alerting\Infrastructure\Dbal\DbalNotificationQueue;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleRepository;
use Kadupul\Alerting\Infrastructure\Dbal\DbalRuleStateStore;
use Kadupul\Alerting\Infrastructure\Dbal\DbalTransactionRunner;
use Kadupul\Alerting\Infrastructure\Dbal\DsstatsBaselineSource;
use Kadupul\Alerting\Infrastructure\Dbal\DsstatsRateSource;
use Kadupul\Tests\Alerting\Fakes\MockClockAdapter;
use Kadupul\Tests\Alerting\Fakes\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

const TABLES = ['alert_rule', 'alert_rule_state', 'alert_incident', 'alert_notification', 'alert_baseline_sample', 'settings', 'host', 'data_local', 'data_template_data', 'data_template_rrd', 'data_source_stats_hourly_cache', 'data_source_stats_hourly_last'];
const ZONE = 'Europe/Berlin';
const STEP = 300;

final class AlertBaselineDatabaseTest extends TestCase
{
    private Connection $database;
    private string $schema = '';
    private MockClock $clock;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a task-owned MariaDB or MySQL server; these alert baseline tests were not run.');
        }
        parse_str(str_replace(';', '&', substr($dsn, strlen('mysql:'))), $target);
        $prefix = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ? 'KADUPUL_TEST_MYSQL_ADMIN_' : 'KADUPUL_TEST_MYSQL_';
        $this->schema = 'alert_baseline_' . bin2hex(random_bytes(6));
        $this->database = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => is_string($target['host'] ?? null) ? $target['host'] : '127.0.0.1',
            'port' => (int) ($target['port'] ?? 3306),
            'user' => (string) (getenv($prefix . 'USER') ?: 'root'),
            'password' => (string) (getenv($prefix . 'PASSWORD') ?: ''),
            'charset' => 'utf8mb4',
        ]);
        $this->database->executeStatement('CREATE DATABASE `' . $this->schema . '`');
        $this->database->executeStatement('USE `' . $this->schema . '`');
        // The legacy session modes (lib/database.php) so the shipped zero-date DDL loads on MySQL too.
        $this->database->executeStatement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION,ALLOW_INVALID_DATES'");
        $this->database->executeStatement("SET time_zone = '+00:00'");

        $sql = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        self::assertIsString($sql);
        foreach (TABLES as $table) {
            self::assertSame(1, preg_match('/^CREATE TABLE `?' . $table . '`? \(.*?^\s*\) [^;\n]*;/ms', $sql, $create), $table);
            $this->database->executeStatement(rtrim($create[0], ';'));
        }
        $this->database->insert('settings', ['name' => 'dsstats_enable', 'value' => 'on']);
        $this->clock = new MockClock(new \DateTimeImmutable('@0'));
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            if ($this->schema !== '') {
                $this->database->executeStatement('DROP DATABASE IF EXISTS `' . $this->schema . '`');
            }
            $this->database->close();
        }
    }

    private function source(): DsstatsBaselineSource
    {
        return new DsstatsBaselineSource($this->database, new \DateTimeZone(ZONE));
    }

    private function evaluate(?RecordingLogger $logger = null): EvaluationReport
    {
        $evaluate = new EvaluateRules(
            new DsstatsRateSource($this->database),
            $this->source(),
            new DbalRuleRepository($this->database),
            new DbalRuleStateStore($this->database),
            new DbalIncidentRepository($this->database),
            new DbalNotificationQueue($this->database),
            new DbalEvaluationCursor($this->database),
            new MockClockAdapter($this->clock),
            new DbalTransactionRunner($this->database),
            $logger ?? new RecordingLogger(),
        );

        return $evaluate(100, 30.0);
    }

    /** A data source without a device and a baseline rule on its traffic_in: k 3, clear 2, floor 1, above the band only. */
    private function watch(int $ruleId, int $localDataId): void
    {
        $this->database->insert('data_local', ['id' => $localDataId, 'host_id' => 0]);
        $this->database->insert('data_template_data', ['local_data_id' => $localDataId, 'rrd_step' => STEP, 'name' => 'ds ' . $localDataId]);
        $this->database->insert('data_template_rrd', ['local_data_id' => $localDataId, 'data_source_name' => 'traffic_in']);
        $this->database->insert('alert_rule', ['id' => $ruleId, 'local_data_id' => $localDataId, 'rrd_name' => 'traffic_in', 'kind' => 'baseline', 'baseline_direction' => 'above', 'baseline_deviations' => 3, 'baseline_clear_deviations' => 2, 'baseline_floor' => 1, 'baseline_min_samples' => 4, 'for_cycles' => 1]);
    }

    /** One dsstats cycle for the series, written as dsstats_poller_output() writes it. */
    private function sample(int $localDataId, int $time, float $rate): void
    {
        $this->database->executeStatement(
            'INSERT INTO data_source_stats_hourly_cache (local_data_id, rrd_name, time, value) VALUES (?, ?, FROM_UNIXTIME(?), ?)',
            [$localDataId, 'traffic_in', $time, $rate],
        );
        $this->database->executeStatement(
            'INSERT INTO data_source_stats_hourly_last (local_data_id, rrd_name, value, calculated) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value), calculated = VALUES(calculated)',
            [$localDataId, 'traffic_in', $rate, $rate],
        );
    }

    private static function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(ZONE)))->getTimestamp();
    }

    public function testTheUpsertCountsEachSampleOnceInTheShippedTable(): void
    {
        $series = new SeriesKey(7, 'traffic_in');
        $nine = self::at('2026-11-09 09:00:00');
        $source = $this->source();

        foreach ([[10.0, 300], [20.0, 600], [20.0, 600], [99.0, 400], [30.0, 900]] as [$rate, $offset]) {
            $source->record([new SeriesObservation($series, new Observation($rate, $nine + $offset, STEP))]);
        }

        $row = $this->database->fetchAssociative('SELECT sample_count, sample_sum, sample_sum_squares, last_observed_at FROM alert_baseline_sample');
        self::assertIsArray($row);
        // A repeated or older sample adds nothing; the sums and count read last_observed_at before it moves.
        self::assertSame([3, 60.0, 1400.0, $nine + 900], [(int) $row['sample_count'], (float) $row['sample_sum'], (float) $row['sample_sum_squares'], (int) $row['last_observed_at']]);
        self::assertEquals(['7:traffic_in' => [new HourBucket(3, 60.0, 1400.0)]], $source->history([new SeriesObservation($series, new Observation(1.0, self::at('2026-11-16 09:30:00'), STEP))]));
    }

    public function testTheEvaluatorBuildsHistoryAcrossADaylightSavingChangeAndThenBreaches(): void
    {
        $this->watch(1, 101);
        // Mondays at 10:20 wall-clock time; Berlin left summer time on 25 October 2026.
        $weeks = ['2026-10-12 10:20:00', '2026-10-19 10:20:00', '2026-10-26 10:20:00', '2026-11-02 10:20:00'];
        foreach ($weeks as $i => $local) {
            $time = self::at($local);
            $this->clock->sleep($time - $this->clock->now()->getTimestamp());
            $this->sample(101, $time, 100.0 + $i % 2);
            $report = $this->evaluate();
            self::assertSame(1, $report->evaluated, $local);
            self::assertSame([], $this->database->fetchFirstColumn('SELECT id FROM alert_incident'), 'Unknown until four weeks of history: ' . $local);
        }
        self::assertSame('4', (string) $this->database->fetchOne('SELECT COUNT(*) FROM alert_baseline_sample'));
        self::assertFalse($this->database->fetchOne('SELECT rule_id FROM alert_rule_state'), 'An unknown verdict on a closed rule writes no state.');

        // The fifth Monday reads all four, two of them recorded in summer time.
        $time = self::at('2026-11-09 10:20:00');
        $this->clock->sleep($time - $this->clock->now()->getTimestamp());
        $this->sample(101, $time, 110.0);
        $this->evaluate();

        $incident = $this->database->fetchAssociative('SELECT status, opened_at FROM alert_incident WHERE rule_id = 1');
        self::assertSame(['status' => 'open', 'opened_at' => (string) $time], array_map('strval', (array) $incident));
        $notification = $this->database->fetchAssociative('SELECT kind, observed_value, threshold FROM alert_notification');
        self::assertIsArray($notification);
        self::assertSame('opened', $notification['kind']);
        self::assertEqualsWithDelta(110.0, (float) $notification['observed_value'], 1e-9);
        // Four observations 100, 101, 100, 101: mean 100.5; sample deviation 0.577 is under the floor of 1, so the edge is 100.5 + 3.
        self::assertEqualsWithDelta(103.5, (float) $notification['threshold'], 1e-9);
    }

    public function testAnUnreadableBaselineRowIsQuarantinedAndTheOthersRun(): void
    {
        $this->watch(1, 101);
        $this->watch(2, 102);
        // A value the column holds but no form writes: zero minimum samples.
        $this->database->update('alert_rule', ['baseline_min_samples' => 0], ['id' => 1]);
        $time = self::at('2026-11-09 10:20:00');
        $this->clock->sleep($time);
        $this->sample(101, $time, 100.0);
        $this->sample(102, $time, 100.0);
        $logger = new RecordingLogger();

        $report = $this->evaluate($logger);

        self::assertSame([1, 1], [$report->evaluated, $report->failed]);
        self::assertCount(1, $logger->records);
        self::assertSame(1, $logger->records[0]['context']['rule']);
        self::assertStringContainsString('alert_rule 1', (string) $logger->records[0]['context']['reason']);
        self::assertSame(['102'], array_map('strval', $this->database->fetchFirstColumn('SELECT local_data_id FROM alert_baseline_sample')), 'Only the usable rule records history.');
    }

    public function testHistoryAgesOutAfterTheLastBaselineRuleIsGoneWithinThePruneBound(): void
    {
        $this->watch(1, 101);
        $now = self::at('2026-11-09 10:20:00');
        $calendar = new BaselineCalendar(new \DateTimeZone(ZONE));
        $oldest = $calendar->oldestKept($calendar->wallHour($now)) - DsstatsBaselineSource::GRACE_HOURS;
        // 6,000 (hour, data source) pairs no band can read, and the oldest hour kept.
        $rows = [];
        foreach ([$oldest - 3, $oldest - 2, $oldest - 1, $oldest] as $hour) {
            for ($id = 1; $id <= 2000; ++$id) {
                $rows[] = [$hour, $id, 'traffic_in', 1, 1.0, 1.0, 1];
            }
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            $this->database->executeStatement(
                'INSERT INTO alert_baseline_sample (local_hour, local_data_id, rrd_name, sample_count, sample_sum, sample_sum_squares, last_observed_at) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?)')),
                array_merge(...$chunk),
            );
        }
        // The last baseline rule is deleted: nothing records any more.
        $this->database->delete('alert_rule', ['id' => 1]);
        $this->clock->sleep($now);
        $old = fn(): int => (int) $this->database->fetchOne('SELECT COUNT(*) FROM alert_baseline_sample WHERE local_hour < ?', [$oldest]);

        $this->evaluate();
        self::assertSame(1000, $old(), 'One run removes at most 5,000 pairs.');
        $this->evaluate();
        self::assertSame(0, $old(), 'The next run removes the rest.');
        self::assertSame(2000, (int) $this->database->fetchOne('SELECT COUNT(*) FROM alert_baseline_sample'), 'The oldest hour kept stays.');
    }
}
