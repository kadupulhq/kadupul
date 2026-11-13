<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Rule;

/**
 * The observations of one series in one wall-clock hour of one earlier
 * week: how many, their mean and the sum of squared deviations from that
 * mean (M2). A band pools the
 * buckets of the same hour of the week, so it describes single observations,
 * the quantity a rule compares, not the spread of hourly means.
 */
final readonly class HourBucket
{
    public function __construct(
        public int $count,
        public float $mean,
        public float $m2,
    ) {}

    /** At least one observation and finite moments: only such a bucket is a sample week. */
    public function isUsable(): bool
    {
        return $this->count >= 1 && is_finite($this->mean) && is_finite($this->m2) && $this->m2 >= 0.0;
    }
}
