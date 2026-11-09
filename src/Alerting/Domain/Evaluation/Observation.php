<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Evaluation;

/**
 * A rate read from dsstats with the time it was sampled and the polling
 * interval (rrd_step) of its data source. ADR 0011 decision 4: it is fresh
 * while it is no older than two of those intervals.
 */
final readonly class Observation
{
    public const int FRESH_INTERVALS = 2;

    /**
     * @param int $observedAt  sample time, Unix seconds
     * @param int $stepSeconds the data source's polling interval
     */
    public function __construct(
        public float $rate,
        public int $observedAt,
        public int $stepSeconds,
    ) {
        if (!is_finite($rate)) {
            throw new \InvalidArgumentException('An observed rate must be a finite number.');
        }
        if ($observedAt < 0) {
            throw new \InvalidArgumentException('An observation time cannot be negative.');
        }
        if ($stepSeconds < 1) {
            throw new \InvalidArgumentException('A polling interval must be at least one second.');
        }
    }

    public function isFreshAt(int $now): bool
    {
        return $now - $this->observedAt <= self::FRESH_INTERVALS * $this->stepSeconds;
    }
}
