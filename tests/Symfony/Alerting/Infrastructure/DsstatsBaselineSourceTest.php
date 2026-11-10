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
use Kadupul\Alerting\Application\Port\SeriesObservation;
use Kadupul\Alerting\Domain\Evaluation\Observation;
use Kadupul\Alerting\Domain\Rule\BaselineCalendar;
use Kadupul\Alerting\Domain\Rule\BaselineRule;
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
        $this->database->executeStatement('CREATE TABLE alert_baseline_sample (local_hour INT NOT NULL, local_data_id INT NOT NULL, rrd_name BLOB NOT NULL, sample_count INT NOT NULL, sample_sum REAL NOT NULL, last_observed_at INT NOT NULL, PRIMARY KEY (local_hour, local_data_id, rrd_name))');
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
        return $this->database->fetchAllAssociative('SELECT local_hour, local_data_id, rrd_name, sample_count, sample_sum, last_observed_at FROM alert_baseline_sample ORDER BY local_hour, local_data_id');
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
            ['local_hour' => $hour, 'local_data_id' => 7, 'rrd_name' => 'traffic_in', 'sample_count' => 2, 'sample_sum' => 30.0, 'last_observed_at' => $nine + 600],
            ['local_hour' => $hour + 1, 'local_data_id' => 7, 'rrd_name' => 'traffic_in', 'sample_count' => 1, 'sample_sum' => 50.0, 'last_observed_at' => $nine + 3600],
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

        self::assertSame(['7:traffic_in' => [31.0, 21.0, 11.0]], $history, 'Bucket means, newest week first; a series with no history is absent.');
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
        self::assertSame(['7:traffic_in' => [42.0]], $source->history([self::seen($series, 1.0, self::at('2026-03-09 09:10:00'))]));
        self::assertSame(['7:traffic_in' => [7.0]], $source->history([self::seen($series, 1.0, self::at('2026-03-09 08:10:00'))]));
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

        self::assertSame(['8:traffic_in' => [10.0, 10.0]], $source->history([self::seen($bad, 1.0, $now), self::seen($good, 1.0, $now)]));

        $this->database->executeStatement("UPDATE alert_baseline_sample SET sample_count = 1, sample_sum = 'lots' WHERE local_data_id = 7");
        self::assertSame(['8:traffic_in' => [10.0, 10.0]], $source->history([self::seen($bad, 1.0, $now), self::seen($good, 1.0, $now)]));
    }

    public function testBucketsOlderThanAnyBandAreRemovedAnHourAtATime(): void
    {
        $series = new SeriesKey(7, 'traffic_in');
        $other = new SeriesKey(8, 'traffic_in');
        $now = self::at('2026-11-09 09:20:00');
        $calendar = new BaselineCalendar(new \DateTimeZone(self::ZONE));
        $oldest = $calendar->oldestKept($calendar->wallHour($now));
        foreach ([$oldest - 2, $oldest - 1, $oldest] as $hour) {
            foreach ([7, 8] as $id) {
                $this->database->insert('alert_baseline_sample', ['local_hour' => $hour, 'local_data_id' => $id, 'rrd_name' => 'traffic_in', 'sample_count' => 1, 'sample_sum' => 1.0, 'last_observed_at' => 1]);
            }
        }
        $source = $this->source();

        $source->record([self::seen($series, 5.0, $now), self::seen($other, 5.0, $now)]);
        self::assertSame([$oldest - 1, $oldest, $calendar->wallHour($now)], array_values(array_unique(array_column($this->rows(), 'local_hour'))), 'One wall hour per call.');
        $source->record([self::seen($series, 5.0, $now + 1)]);
        $source->record([self::seen($series, 5.0, $now + 2)]);
        self::assertSame([$oldest, $calendar->wallHour($now)], array_values(array_unique(array_column($this->rows(), 'local_hour'))), 'The oldest hour a band reads stays.');
        self::assertCount(BaselineRule::HISTORY_WEEKS, $calendar->priorWeeks(0));
        self::assertSame($oldest, $calendar->priorWeeks($calendar->wallHour($now))[BaselineRule::HISTORY_WEEKS - 1]);
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
        self::assertSame([1201.0], $history['1201:traffic_in']);
        self::assertCount(3, array_filter($writes, static fn(string $sql): bool => str_starts_with($sql, 'INSERT')), '500 series per insert.');
        self::assertCount(3, $this->log->statements, 'One read per 500 series of one hour, never one per series.');
    }
}
