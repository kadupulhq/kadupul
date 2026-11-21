<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Domain\Rule\HourBucket;

/**
 * Hour-of-week history for baseline rules (ADR 0011 decision 3). dsstats
 * keeps no per-hour history (its hourly and daily tables hold one rolling
 * row per series), so the evaluator records the fresh rates it reads into
 * one bucket per series and wall-clock hour, and a band reads the same hour
 * of earlier weeks back. Both calls take a whole batch, never one rule.
 */
interface BaselineSource
{
    /** (wall hour, data source) pairs one prune() call removes at most. */
    public const int PRUNE_LIMIT = 5000;

    /**
     * Adds each observation to its series' bucket for the wall-clock hour it
     * was sampled in. An observation time at or before the newest one the
     * bucket already counted is not added again, so two runs between polls
     * do not count one sample twice. It removes nothing: see prune().
     *
     * @param list<SeriesObservation> $observations
     */
    public function record(array $observations): void;

    /**
     * For each observation's series, keyed by SeriesKey::id(): the bucket
     * (count, sum and sum of squares of its observations) of the same
     * wall-clock hour in each earlier week that has one, newest first, at
     * most BaselineRule::HISTORY_WEEKS of them. The observation's
     * own week is never included. A series with no such bucket, or one whose
     * buckets cannot be read, is absent, which a baseline rule reads as
     * unknown.
     *
     * @param list<SeriesObservation> $observations
     *
     * @return array<string, list<HourBucket>>
     */
    public function history(array $observations): array;

    /**
     * Removes buckets that no band at $now or later can read, at most
     * PRUNE_LIMIT (wall hour, data source) pairs per call, oldest first, and
     * returns how many rows it removed. The evaluator calls it once per run,
     * whether or not any baseline rule remains, so history ages out after
     * the last rule on a series is gone.
     *
     * @throws BaselinePruneFailed when the rows could not be removed; they stay for the next call
     *
     * @param int $now Unix seconds
     */
    public function prune(int $now): int;
}
