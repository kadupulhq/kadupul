<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

/**
 * Hour-of-week history for baseline rules (ADR 0011 decision 3). dsstats
 * keeps no per-hour history (its hourly and daily tables hold one rolling
 * row per series), so the evaluator records the fresh rates it reads into
 * one bucket per series and wall-clock hour, and a band reads the same hour
 * of earlier weeks back. Both calls take a whole batch, never one rule.
 */
interface BaselineSource
{
    /**
     * Adds each observation to its series' bucket for the wall-clock hour it
     * was sampled in. An observation time at or before the newest one the
     * bucket already counted is not added again, so two runs between polls
     * do not count one sample twice. Buckets older than any band reads are
     * removed, a bounded number per call.
     *
     * @param list<SeriesObservation> $observations
     */
    public function record(array $observations): void;

    /**
     * For each observation's series, keyed by SeriesKey::id(): the mean rate
     * of the same wall-clock hour in each earlier week that has one, newest
     * first, at most BaselineRule::HISTORY_WEEKS of them. The observation's
     * own week is never included. A series with no such bucket, or one whose
     * buckets cannot be read, is absent, which a baseline rule reads as
     * unknown.
     *
     * @param list<SeriesObservation> $observations
     *
     * @return array<string, list<float>>
     */
    public function history(array $observations): array;
}
