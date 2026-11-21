<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Kadupul\Alerting\Application\Port\RateSource;
use Kadupul\Alerting\Domain\Evaluation\Observation;
use Kadupul\Alerting\Domain\Rule\SeriesKey;

/**
 * Reads rates that dsstats_poller_output() (lib/dsstats.php) computes on the
 * main poller, with or without Boost (ADR 0011, Boost).
 *
 * The rate is `calculated` from data_source_stats_hourly_last, never the raw
 * `value`. That table has no time, so the observation time is the newest
 * `time` that the same writer call stored for the series in
 * data_source_stats_hourly_cache, whose `value` is the same rate. A series
 * counts only when the cache value at that time equals `calculated`; a pair
 * that differs (the writer is between its two inserts, or an older sample was
 * written later) has no known time and is left out, which reads as unknown.
 * The newest time is read per series through the BTREE key added to the cache
 * by install/upgrades/1_2_40.php, not by scanning the table: its primary key is
 * a HASH key and cannot serve a lookup on the data source alone.
 * The polling interval is the data source's rrd_step, which dsstats also
 * divides counter deltas by.
 *
 * UNIX_TIMESTAMP() on a TIMESTAMP column returns the stored instant whatever
 * this session's time zone. The writer formats the sample with PHP date() and
 * the poller's session stores it, so the instant is right while those two
 * agree, which Cacti already requires of a poller.
 */
final readonly class DsstatsRateSource implements RateSource
{
    /** Data sources per query, bounding the IN lists. */
    private const int CHUNK = 500;

    public function __construct(private Connection $database) {}

    public function available(): bool
    {
        // read_config_option() treats a missing row as the default, '' (off).
        if ($this->database->fetchOne("SELECT value FROM settings WHERE name = 'dsstats_enable'") !== 'on') {
            return false;
        }
        foreach (['data_source_stats_hourly_last', 'data_source_stats_hourly_cache'] as $table) {
            try {
                $this->database->fetchOne('SELECT 1 FROM ' . $table . ' WHERE 1 = 0');
            } catch (TableNotFoundException) {
                return false;
            }
        }

        return true;
    }

    public function currentRates(array $keys): array
    {
        $wanted = [];
        foreach ($keys as $key) {
            $wanted[$key->localDataId][$key->rrdName] = true;
        }

        $found = [];
        foreach (array_chunk(array_keys($wanted), self::CHUNK) as $ids) {
            $rows = $this->database->fetchAllAssociative(
                'SELECT l.local_data_id, l.rrd_name, l.calculated, UNIX_TIMESTAMP(c.time) AS observed_at, d.rrd_step
				FROM data_template_data AS d
				INNER JOIN data_source_stats_hourly_cache AS c
				ON c.local_data_id = d.local_data_id
				INNER JOIN data_source_stats_hourly_last AS l
				ON l.local_data_id = c.local_data_id
				AND l.rrd_name = c.rrd_name
				WHERE d.local_data_id IN (?)
				AND d.rrd_step > 0
				AND l.calculated IS NOT NULL
				AND c.value = l.calculated
				AND c.time = (
					SELECT MAX(m.time)
					FROM data_source_stats_hourly_cache AS m
					WHERE m.local_data_id = c.local_data_id
					AND m.rrd_name = c.rrd_name
				)',
                [$ids],
                [ArrayParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                $id = self::int($row['local_data_id']);
                $name = $row['rrd_name'];
                // The column collation is case-insensitive; a rule names one exact series.
                if (!is_string($name) || !isset($wanted[$id][$name])) {
                    continue;
                }
                $found[(new SeriesKey($id, $name))->id()] = new Observation(
                    self::float($row['calculated']),
                    self::int($row['observed_at']),
                    self::int($row['rrd_step']),
                );
            }
        }

        return $found;
    }

    private static function int(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('The dsstats tables returned an unexpected row.');
        }

        return (int) $value;
    }

    private static function float(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('The dsstats tables returned an unexpected row.');
        }

        return (float) $value;
    }
}
