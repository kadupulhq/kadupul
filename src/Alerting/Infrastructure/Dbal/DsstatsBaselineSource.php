<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Kadupul\Alerting\Application\Port\BaselineSource;
use Kadupul\Alerting\Application\Port\SeriesObservation;
use Kadupul\Alerting\Domain\Rule\BaselineCalendar;
use Kadupul\Alerting\Domain\Rule\HourBucket;
use Kadupul\Alerting\Domain\Rule\SeriesKey;

/**
 * Hour-of-week history of dsstats rates in alert_baseline_sample (ADR 0011
 * decision 3). No dsstats table can serve it: data_source_stats_hourly and
 * _daily hold one rolling row per series, overwritten each run
 * (poller_dsstats.php dsstats_insert_hourly_data_into_cache(),
 * lib/dsstats.php dsstats_write_buffer()), and the hourly cache keeps only
 * dsstats_hourly_duration minutes.
 *
 * Each row is one series in one wall-clock hour of the installation time
 * zone (BaselineCalendar), with the count, sum and sum of squares of the
 * fresh rates the evaluator read in that hour and the newest observation
 * time counted. A band pools the buckets of one hour of the week. The
 * installation time zone is PHP's default time zone in the evaluator, the CLI date.timezone that the
 * poller, which starts it with the same PHP binary, writes dsstats times in.
 *
 * Every statement takes a bounded list: at most CHUNK series, and the
 * HISTORY_WEEKS wall hours of one hour of the week. prune() removes rows a
 * band can no longer read, at most PRUNE_LIMIT (wall hour, data source)
 * pairs per call in chunks of CHUNK, keeping GRACE_HOURS more than a band at
 * the prune time reads.
 */
final readonly class DsstatsBaselineSource implements BaselineSource
{
    /** Series per statement, bounding the IN lists and the multi-row insert. */
    private const int CHUNK = 500;
    /**
     * Wall hours kept beyond the oldest one a band at the prune time reads. An
     * observation stays fresh for two polling intervals, so a run can still
     * judge a rate from an earlier hour, whose band reaches that much further
     * back; a day covers polling intervals up to 12 hours.
     */
    public const int GRACE_HOURS = 24;

    private BaselineCalendar $calendar;

    public function __construct(private Connection $database, ?\DateTimeZone $timeZone = null)
    {
        $this->calendar = new BaselineCalendar($timeZone ?? new \DateTimeZone(date_default_timezone_get()));
    }

    public function record(array $observations): void
    {
        $rows = [];
        foreach ($observations as $entry) {
            $hour = $this->calendar->wallHour($entry->observation->observedAt);
            $rate = $entry->observation->rate;
            $rows[$hour . '|' . $entry->series->id()] = [$hour, $entry->series->localDataId, $entry->series->rrdName, $rate, $rate * $rate, $entry->observation->observedAt];
        }
        if ($rows === []) {
            return;
        }
        foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
            $this->database->executeStatement($this->upsert(count($chunk)), array_merge(...$chunk));
        }
    }

    public function history(array $observations): array
    {
        /** @var array<int, array<string, SeriesKey>> $wanted series by the wall hour they were observed in */
        $wanted = [];
        foreach ($observations as $entry) {
            $wanted[$this->calendar->wallHour($entry->observation->observedAt)][$entry->series->id()] = $entry->series;
        }

        $found = [];
        $unreadable = [];
        foreach ($wanted as $hour => $series) {
            $ids = array_values(array_unique(array_map(static fn(SeriesKey $key): int => $key->localDataId, $series)));
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $rows = $this->database->fetchAllAssociative(
                    'SELECT local_hour, local_data_id, rrd_name, sample_count, sample_sum, sample_sum_squares
                    FROM alert_baseline_sample
                    WHERE local_hour IN (?) AND local_data_id IN (?)',
                    [$this->calendar->priorWeeks($hour), $chunk],
                    [ArrayParameterType::INTEGER, ArrayParameterType::INTEGER],
                );
                foreach ($rows as $row) {
                    // rrd_name is varbinary: a byte comparison, one exact series.
                    $key = is_string($row['rrd_name']) ? self::key($row['local_data_id'], $row['rrd_name']) : null;
                    if ($key === null || !isset($series[$key])) {
                        continue;
                    }
                    $bucket = self::bucket($row);
                    if ($bucket === null) {
                        $unreadable[$key] = true;
                        continue;
                    }
                    $found[$key][StoredValue::int($row['local_hour'], 'alert_baseline_sample', 'local_hour')] = $bucket;
                }
            }
        }

        $history = [];
        foreach ($found as $key => $buckets) {
            if (isset($unreadable[$key])) {
                // A bucket that cannot be read leaves the band unknown rather than built from the rest.
                continue;
            }
            krsort($buckets);
            $history[$key] = array_values($buckets);
        }

        return $history;
    }

    /**
     * Adds one observation per row. MariaDB and MySQL apply the assignments
     * left to right, so the sum and count compare against the stored
     * last_observed_at before it moves; SQLite (the store tests) reads the
     * old row in every assignment.
     */
    private function upsert(int $rows): string
    {
        $sql = 'INSERT INTO alert_baseline_sample (local_hour, local_data_id, rrd_name, sample_sum, sample_sum_squares, last_observed_at, sample_count) VALUES '
            . implode(', ', array_fill(0, $rows, '(?, ?, ?, ?, ?, ?, 1)'));
        if ($this->database->getDatabasePlatform() instanceof SQLitePlatform) {
            return $sql . ' ON CONFLICT (local_hour, local_data_id, rrd_name) DO UPDATE SET
                sample_sum = CASE WHEN excluded.last_observed_at > alert_baseline_sample.last_observed_at THEN alert_baseline_sample.sample_sum + excluded.sample_sum ELSE alert_baseline_sample.sample_sum END,
                sample_sum_squares = CASE WHEN excluded.last_observed_at > alert_baseline_sample.last_observed_at THEN alert_baseline_sample.sample_sum_squares + excluded.sample_sum_squares ELSE alert_baseline_sample.sample_sum_squares END,
                sample_count = CASE WHEN excluded.last_observed_at > alert_baseline_sample.last_observed_at THEN alert_baseline_sample.sample_count + 1 ELSE alert_baseline_sample.sample_count END,
                last_observed_at = MAX(alert_baseline_sample.last_observed_at, excluded.last_observed_at)';
        }

        return $sql . ' ON DUPLICATE KEY UPDATE
            sample_sum = IF(VALUES(last_observed_at) > last_observed_at, sample_sum + VALUES(sample_sum), sample_sum),
            sample_sum_squares = IF(VALUES(last_observed_at) > last_observed_at, sample_sum_squares + VALUES(sample_sum_squares), sample_sum_squares),
            sample_count = IF(VALUES(last_observed_at) > last_observed_at, sample_count + 1, sample_count),
            last_observed_at = GREATEST(last_observed_at, VALUES(last_observed_at))';
    }

    /**
     * Up to PRUNE_LIMIT (wall hour, data source) pairs older than any band at
     * $now reads, less GRACE_HOURS, oldest first: each chunk is one SELECT of
     * at most CHUNK pairs and one DELETE per wall hour in it.
     */
    public function prune(int $now): int
    {
        $before = $this->calendar->oldestKept($this->calendar->wallHour($now)) - self::GRACE_HOURS;
        $removed = 0;
        for ($pairs = 0; $pairs < self::PRUNE_LIMIT; $pairs += self::CHUNK) {
            $rows = $this->database->fetchAllAssociative(
                'SELECT DISTINCT local_hour, local_data_id FROM alert_baseline_sample
                WHERE local_hour < ? ORDER BY local_hour, local_data_id LIMIT ?',
                [$before, min(self::CHUNK, self::PRUNE_LIMIT - $pairs)],
                [ParameterType::INTEGER, ParameterType::INTEGER],
            );
            if ($rows === []) {
                break;
            }
            $byHour = [];
            foreach ($rows as $row) {
                $byHour[StoredValue::int($row['local_hour'], 'alert_baseline_sample', 'local_hour')][] = StoredValue::int($row['local_data_id'], 'alert_baseline_sample', 'local_data_id');
            }
            foreach ($byHour as $hour => $ids) {
                $removed += (int) $this->database->executeStatement(
                    'DELETE FROM alert_baseline_sample WHERE local_hour = ? AND local_data_id IN (?)',
                    [$hour, $ids],
                    [ParameterType::INTEGER, ArrayParameterType::INTEGER],
                );
            }
            if (count($rows) < self::CHUNK) {
                break;
            }
        }

        return $removed;
    }

    private static function key(mixed $localDataId, string $rrdName): ?string
    {
        try {
            return (new SeriesKey(StoredValue::int($localDataId, 'alert_baseline_sample', 'local_data_id'), $rrdName))->id();
        } catch (\InvalidArgumentException | StoredAlertingRowUnreadable) {
            return null;
        }
    }

    /**
     * The bucket, or null when its count or sums cannot be used.
     *
     * @param array<string, mixed> $row
     */
    private static function bucket(array $row): ?HourBucket
    {
        try {
            $bucket = new HourBucket(
                StoredValue::int($row['sample_count'], 'alert_baseline_sample', 'sample_count'),
                StoredValue::float($row['sample_sum'], 'alert_baseline_sample', 'sample_sum'),
                StoredValue::float($row['sample_sum_squares'], 'alert_baseline_sample', 'sample_sum_squares'),
            );
        } catch (StoredAlertingRowUnreadable) {
            return null;
        }

        return $bucket->isUsable() ? $bucket : null;
    }
}
