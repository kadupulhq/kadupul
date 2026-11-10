<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Rule;

/**
 * The observations of one series in one wall-clock hour of one earlier
 * week: how many, their sum and the sum of their squares. A band pools the
 * buckets of the same hour of the week, so it describes single observations,
 * the quantity a rule compares, not the spread of hourly means.
 */
final readonly class HourBucket
{
    public function __construct(
        public int $count,
        public float $sum,
        public float $sumSquares,
    ) {}

    /** At least one observation and finite sums: only such a bucket is a sample week. */
    public function isUsable(): bool
    {
        return $this->count >= 1 && is_finite($this->sum) && is_finite($this->sumSquares);
    }
}
