<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Domain\Evaluation\Observation;
use Kadupul\Alerting\Domain\Rule\SeriesKey;

/** Computed rates (dsstats `calculated`, never the raw `value`), ADR 0011 decision 1. */
interface RateSource
{
    /** False when the source cannot produce rates at all; evaluation is then inactive. */
    public function available(): bool;

    /**
     * The latest observation for each key, keyed by SeriesKey::id(). A key is
     * absent when there is no row, the rate is NULL or no observation time is
     * known: absent never means zero.
     *
     * @param list<SeriesKey> $keys
     *
     * @return array<string, Observation>
     */
    public function currentRates(array $keys): array;
}
