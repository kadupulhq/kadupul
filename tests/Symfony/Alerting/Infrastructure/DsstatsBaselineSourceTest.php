<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Infrastructure;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Kadupul\Alerting\Application\Port\BaselineSource;
use Kadupul\Alerting\Application\Port\SeriesObservation;
use Kadupul\Alerting\Domain\Evaluation\Observation;
use Kadupul\Alerting\Domain\Rule\BaselineCalendar;
use Kadupul\Alerting\Domain\Rule\BaselineRule;
use Kadupul\Alerting\Domain\Rule\HourBucket;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Kadupul\Alerting\Infrastructure\Dbal\DsstatsBaselineSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The baseline history's SQL on SQLite. The shipped InnoDB table and the
 * MariaDB upsert are covered by tests/integration/AlertBaselineDatabaseTest.php.
 */
final class DsstatsBaselineSourceTest extends TestCase
{
    private const string ZONE = 'America/New_York';

    private Connection $database;
    private AbstractLogger $log;

    protected function setUp(): void
    {
        // Records each statement's SQL, so a test can count the statements a batch costs.
        $this->log = new class extends AbstractLogger {
            /** @var list<string> */
            public array $statements = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if (isset($context['sql']) && is_string($context['sql'])) {
                    $this->statements[] = $context['sql'];
                }
            }
        };
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([new Middleware($this->log)]));
        $this->database->executeStatement('CREATE TABLE alert_baseline_sample (local_hour INT NOT NULL, local_data_id INT NOT NULL, rrd_name BLOB NOT NULL, sample_count INT NOT NULL, sample_sum REAL NOT NULL, sample_sum_squares REAL NOT NULL, last_observed_at INT NOT NULL, PRIMARY KEY (local_hour, local_data_id, rrd_name))');
    }

    private function source(): DsstatsBaselineSource
    {
        return new DsstatsBaselineSource($this->database, new \DateTimeZone(self::ZONE));
    }

    private static function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }

    /** The same wall-clock time $weeks weeks earlier; 1 November 2026 ended daylight saving time. */
    private static function weeksBefore(int $instant, int $weeks): int
    {
        return (new \DateTimeImmutable('@' . $instant))->setTimezone(new \DateTimeZone(self::ZONE))->modify('-' . $weeks . ' weeks')->getTimestamp();
    }

    private static function seen(SeriesKey $series, float $rate, int $at): SeriesObservation
    {
        return new SeriesObservation($series, new Observation($rate, $at, 300));
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->database->fetchAllAssociative('SELECT local_hour, local_data_id, rrd_name, sample_count, sample_sum, sample_sum_squares, last_observed_at FROM alert_baseline_sample ORDER BY local_hour, local_data_id');
    }

    public function testEachSampleIsAddedToItsWallHourOnce(): void
    {
        $series = new SeriesKey(7, 'traffic_in');
        $nine = self::at('2026-11-09 09:00:00');
        $source = $this->source();

        $source->record([self::seen($series, 10.0, $nine + 300)]);
        $source->record([self::seen($series, 20.0, $nine + 600)]);
        // The same sample read again, and an older one written late, are not counted.
        $source->record([self::seen($series, 20.0, $nine + 600)]);
        $source->record([self::seen($series, 99.0, $nine + 400)]);
        // The next hour is its own bucket.
        $source->record([self::seen($series, 50.0, $nine + 3600)]);

        $hour = (new BaselineCalendar(new \DateTimeZone(self::ZONE)))->wallHour($nine);
        self::assertEquals([
            ['local_hour' => $hour, 'local_data_id' => 7, 'rrd_name' => 'traffic_in', 'sample_count' => 2, 'sample_sum' => 30.0, 'sample_sum_squares' => 500.0, 'last_observed_at' => $nine + 600],
            ['local_hour' => $hour + 1, 'local_data_id' => 7, 'rrd_name' => 'traffic_in', 'sample_count' => 1, 'sample_sum' => 50.0, 'sample_sum_squares' => 2500.0, 'last_observed_at' => $nine + 3600],
        ], $this->rows());
    }

    public function testHistoryIsTheSameHourOfEarlierWeeksNewestFirst(): void
    {
        $series = new SeriesKey(7, 'traffic_in');
        $now = self::at('2026-11-09 09:20:00');
        $source = $this->source();
        // Weeks 1 to 3 back at 09:xx, plus noise the band must not read.
        foreach ([1 => 30.0, 2 => 20.0, 3 => 10.0] as $weeks => $rate) {
            $source->record([self::seen($series, $rate, self::weeksBefore($now, $weeks))]);
            $source->record([self::seen($series, $rate + 2.0, self::weeksBefore($now, $weeks) + 60)]);
        }
        $source->record([self::seen($series, 500.0, $now - 3600)]); // an hour earlier
        $source->record([self::seen($series, 600.0, $now - 60)]);   // this week's own hour
        $source->record([self::seen(new SeriesKey(7, 'Traffic_In'), 700.0, self::weeksBefore($now, 1))]); // another series, by one byte
        $source->record([self::seen(new SeriesKey(8, 'traffic_in'), 800.0, self::weeksBefore($now, 1))]);
        $source->record([self::seen($series, 900.0, self::weeksBefore($now, 9))]); // older than the history kept

        $history = $source->history([self::seen($series, 1.0, $now), self::seen(new SeriesKey(9, 'traffic_in'), 1.0, $now)]);

        // 30 and 32, 20 and 22, 10 and 12: newest week first; a series with no history is absent.
        self::assertEquals(['7:traffic_in' => [new HourBucket(2, 62.0, 1924.0), new HourBucket(2, 42.0, 884.0), new HourBucket(2, 22.0, 244.0)]], $history);
    }

    public function testADaylightSavingChangeKeepsTheHourOfTheWeek(): void
    {
        $series = new SeriesKey(7, 'traffic_in');
        $source = $this->source();
        // Monday 09:30 EST, the week before clocks went forward on 8 March 2026.
        $source->record([self::seen($series, 42.0, self::at('2026-03-02 09:30:00'))]);
        // 08:30 EST that day is the UTC hour (13:00) of 09:10 EDT a week later: a UTC calendar would read it instead.
        $source->record([self::seen($series, 7.0, self::at('2026-03-02 08:30:00'))]);

        // Monday 09:10 EDT is 167 real hours later, yet the same hour of the week.
        self::assertEquals(['7:traffic_in' => [new HourBucket(1, 42.0, 1764.0)]], $source->history([self::seen($series, 1.0, self::at('2026-03-09 09:10:00'))]));
        self::assertEquals(['7:traffic_in' => [new HourBucket(1, 7.0, 49.0)]], $source->history([self::seen($series, 1.0, self::at('2026-03-09 08:10:00'))]));
    }

    public function testAnUnreadableBucketLeavesItsSeriesWithoutHistory(): void
    {
        $bad = new SeriesKey(7, 'traffic_in');
        $good = new SeriesKey(8, 'traffic_in');
        $now = self::at('2026-11-09 09:20:00');
        $source = $this->source();
        foreach ([1, 2] as $weeks) {
            $source->record([self::seen($bad, 10.0, self::weeksBefore($now, $weeks)), self::seen($good, 10.0, self::weeksBefore($now, $weeks))]);
        }
        $this->database->executeStatement('UPDATE alert_baseline_sample SET sample_count = 0 WHERE local_data_id = 7 AND last_observed_at = ?', [self::weeksBefore($now, 2)]);

        $good10 = ['8:traffic_in' => [new HourBucket(1, 10.0, 100.0), new HourBucket(1, 10.0, 100.0)]];
        self::assertEquals($good10, $source->history([self::seen($bad, 1.0, $now), self::seen($good, 1.0, $now)]));

        $this->database->executeStatement("UPDATE alert_baseline_sample SET sample_count = 1, sample_sum = 'lots' WHERE local_data_id = 7");
        self::assertEquals($good10, $source->history([self::seen($bad, 1.0, $now), self::seen($good, 1.0, $now)]));

        $this->database->executeStatement("UPDATE alert_baseline_sample SET sample_sum = 10, sample_sum_squares = 'lots' WHERE local_data_id = 7");
        self::assertEquals($good10, $source->history([self::seen($bad, 1.0, $now), self::seen($good, 1.0, $now)]));
    }

    private function insertBucket(int $hour, int $localDataId, string $rrdName = 'traffic_in'): void
    {
        $this->database->insert('alert_baseline_sample', ['local_hour' => $hour, 'local_data_id' => $localDataId, 'rrd_name' => $rrdName, 'sample_count' => 1, 'sample_sum' => 1.0, 'sample_sum_squares' => 1.0, 'last_observed_at' => 1]);
    }

    public function testRecordingRemovesNothing(): void
    {
        $now = self::at('2026-11-09 09:20:00');
        $calendar = new BaselineCalendar(new \DateTimeZone(self::ZONE));
        $this->insertBucket($calendar->oldestKept($calendar->wallHour($now)) - DsstatsBaselineSource::GRACE_HOURS - 5, 7);

        $this->source()->record([self::seen(new SeriesKey(7, 'traffic_in'), 5.0, $now)]);

        self::assertCount(2, $this->rows(), 'Pruning belongs to the run, not to record().');
    }

    public function testPruneRemovesOnlyBucketsNoBandCanReadOldestFirstWithinTheBound(): void
    {
        $now = self::at('2026-11-09 09:20:00');
        $calendar = new BaselineCalendar(new \DateTimeZone(self::ZONE));
        self::assertSame($calendar->oldestKept($calendar->wallHour($now)), $calendar->priorWeeks($calendar->wallHour($now))[BaselineRule::HISTORY_WEEKS - 1]);
        // A rate observed in the previous hour may still be fresh; its band reads the hour before the oldest one at $now.
        $oldest = $calendar->oldestKept($calendar->wallHour($now)) - DsstatsBaselineSource::GRACE_HOURS;
        // 3 old wall hours x 2,000 data sources (one with a second RRD name), plus the oldest hour kept.
        for ($id = 1; $id <= 2000; ++$id) {
            foreach ([$oldest - 3, $oldest - 2, $oldest - 1, $oldest] as $hour) {
                $this->insertBucket($hour, $id);
            }
        }
        $this->insertBucket($oldest - 3, 1, 'traffic_out');
        $source = $this->source();
        $this->log->statements = [];

        self::assertSame(5001, $source->prune($now), 'At most 5,000 (hour, data source) pairs; both names of data source 1 go.');
        self::assertSame(10, count(array_filter($this->log->statements, static fn(string $sql): bool => str_starts_with($sql, 'SELECT'))), 'Chunks of 500 pairs.');
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM alert_baseline_sample WHERE local_hour = ?', [$oldest - 3]), 'Oldest hour first.');
        self::assertSame(0, (int) $this->database->fetchOne('SELECT COUNT(*) FROM alert_baseline_sample WHERE local_hour = ?', [$oldest - 2]));
        self::assertSame(1000, (int) $this->database->fetchOne('SELECT COUNT(*) FROM alert_baseline_sample WHERE local_hour = ?', [$oldest - 1]), 'The bound stops part way through an hour.');

        self::assertSame(1000, $source->prune($now), 'The next run finishes it.');
        self::assertSame(0, $source->prune($now), 'Nothing left to remove.');
        self::assertSame([$oldest], array_values(array_unique(array_map('intval', array_column($this->rows(), 'local_hour')))), 'The oldest hour kept stays.');
        self::assertSame(5000, BaselineSource::PRUNE_LIMIT);
    }

    public function testPruneKeepsTheHistoryOfARateStillFreshFromThePreviousHour(): void
    {
        $series = new SeriesKey(7, 'traffic_in');
        $now = self::at('2026-11-09 09:05:00');
        $source = $this->source();
        // Observed at 08:55 with a 300 s step, still fresh at 09:05: its band's oldest week is one hour older than 09:xx's.
        $observed = $now - 600;
        $source->record([self::seen($series, 40.0, self::weeksBefore($observed, BaselineRule::HISTORY_WEEKS))]);

        self::assertSame(0, $source->prune($now));
        self::assertEquals(['7:traffic_in' => [new HourBucket(1, 40.0, 1600.0)]], $source->history([self::seen($series, 1.0, $observed)]));
    }

    public function testABatchIsReadAndWrittenInBoundedStatements(): void
    {
        $now = self::at('2026-11-09 09:20:00');
        $series = [];
        for ($id = 1; $id <= 1201; ++$id) {
            $series[] = new SeriesKey($id, 'traffic_in');
        }
        $source = $this->source();
        $source->record(array_map(fn(SeriesKey $key): SeriesObservation => self::seen($key, (float) $key->localDataId, self::weeksBefore($now, 1)), $series));
        $writes = $this->log->statements;
        $this->log->statements = [];

        $history = $source->history(array_map(fn(SeriesKey $key): SeriesObservation => self::seen($key, 1.0, $now), $series));

        self::assertCount(1201, $history);
        self::assertEquals([new HourBucket(1, 1201.0, 1201.0 * 1201.0)], $history['1201:traffic_in']);
        self::assertCount(3, array_filter($writes, static fn(string $sql): bool => str_starts_with($sql, 'INSERT')), '500 series per insert.');
        self::assertCount(3, $this->log->statements, 'One read per 500 series of one hour, never one per series.');
    }
}
