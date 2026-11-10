<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Rule;

/**
 * The expected rate for one hour of the week: the mean of the previous
 * weeks' hourly means and the deviation a band is measured in. The deviation
 * is the sample standard deviation of those means, raised to the rule's
 * floor, so it is always greater than zero and a band always has width.
 */
final readonly class BaselineBand
{
    public function __construct(
        public float $mean,
        public float $deviation,
    ) {
        if (!is_finite($mean) || !is_finite($deviation) || $deviation <= 0.0) {
            throw new InvalidRule('A baseline band needs a finite mean and a finite deviation greater than zero.');
        }
    }

    public function upper(float $multiplier): float
    {
        return $this->mean + $multiplier * $this->deviation;
    }

    public function lower(float $multiplier): float
    {
        return $this->mean - $multiplier * $this->deviation;
    }
}
