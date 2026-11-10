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
 * zone (BaselineCalendar), with the count and sum of the fresh rates the
 * evaluator read in that hour and the newest observation time counted. Its
 * mean is one sample of that hour of the week. The installation time zone is
 * PHP's default time zone in the evaluator, the CLI date.timezone that the
 * poller, which starts it with the same PHP binary, writes dsstats times in.
 *
 * Every statement takes a bounded list: at most CHUNK series, and the
 * HISTORY_WEEKS wall hours of one hour of the week. Rows a band can no longer
 * read are removed one wall hour per call, at most one row per series.
 */
final readonly class DsstatsBaselineSource implements BaselineSource
{
    /** Series per statement, bounding the IN lists and the multi-row insert. */
    private const int CHUNK = 500;

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
            $rows[$hour . '|' . $entry->series->id()] = [$hour, $entry->series->localDataId, $entry->series->rrdName, $entry->observation->rate, $entry->observation->observedAt];
        }
        if ($rows === []) {
            return;
        }
        foreach (array_chunk(array_values($rows), self::CHUNK) as $chunk) {
            $this->database->executeStatement($this->upsert(count($chunk)), array_merge(...$chunk));
        }
        $this->prune(min(array_column($rows, 0)));
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
                    'SELECT local_hour, local_data_id, rrd_name, sample_count, sample_sum
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
                    $mean = self::mean($row);
                    if ($mean === null) {
                        $unreadable[$key] = true;
                        continue;
                    }
                    $found[$key][StoredValue::int($row['local_hour'], 'alert_baseline_sample', 'local_hour')] = $mean;
                }
            }
        }

        $history = [];
        foreach ($found as $key => $means) {
            if (isset($unreadable[$key])) {
                // A bucket that cannot be read leaves the band unknown rather than built from the rest.
                continue;
            }
            krsort($means);
            $history[$key] = array_values($means);
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
        $sql = 'INSERT INTO alert_baseline_sample (local_hour, local_data_id, rrd_name, sample_sum, last_observed_at, sample_count) VALUES '
            . implode(', ', array_fill(0, $rows, '(?, ?, ?, ?, ?, 1)'));
        if ($this->database->getDatabasePlatform() instanceof SQLitePlatform) {
            return $sql . ' ON CONFLICT (local_hour, local_data_id, rrd_name) DO UPDATE SET
                sample_sum = CASE WHEN excluded.last_observed_at > alert_baseline_sample.last_observed_at THEN alert_baseline_sample.sample_sum + excluded.sample_sum ELSE alert_baseline_sample.sample_sum END,
                sample_count = CASE WHEN excluded.last_observed_at > alert_baseline_sample.last_observed_at THEN alert_baseline_sample.sample_count + 1 ELSE alert_baseline_sample.sample_count END,
                last_observed_at = MAX(alert_baseline_sample.last_observed_at, excluded.last_observed_at)';
        }

        return $sql . ' ON DUPLICATE KEY UPDATE
            sample_sum = IF(VALUES(last_observed_at) > last_observed_at, sample_sum + VALUES(sample_sum), sample_sum),
            sample_count = IF(VALUES(last_observed_at) > last_observed_at, sample_count + 1, sample_count),
            last_observed_at = GREATEST(last_observed_at, VALUES(last_observed_at))';
    }

    /** Removes the oldest wall hour if no band from $hour on can read it. */
    private function prune(int $hour): void
    {
        $oldest = $this->database->fetchOne('SELECT MIN(local_hour) FROM alert_baseline_sample');
        if ($oldest === null || $oldest === false) {
            return;
        }
        $oldest = StoredValue::int($oldest, 'alert_baseline_sample', 'local_hour');
        if ($oldest >= $this->calendar->oldestKept($hour)) {
            return;
        }
        $this->database->executeStatement('DELETE FROM alert_baseline_sample WHERE local_hour = ?', [$oldest], [ParameterType::INTEGER]);
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
     * The bucket's mean, or null when its count or sum cannot be used.
     *
     * @param array<string, mixed> $row
     */
    private static function mean(array $row): ?float
    {
        try {
            $count = StoredValue::int($row['sample_count'], 'alert_baseline_sample', 'sample_count');
            $sum = StoredValue::float($row['sample_sum'], 'alert_baseline_sample', 'sample_sum');
        } catch (StoredAlertingRowUnreadable) {
            return null;
        }
        if ($count < 1 || !is_finite($sum / $count)) {
            return null;
        }

        return $sum / $count;
    }
}
