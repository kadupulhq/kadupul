<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Application;

use Kadupul\Telemetry\Contract\CounterWidth;

final readonly class CounterNormalizationPolicy
{
    /**
     * @param int $maxGapSeconds longest spacing that still counts as consecutive readings
     * @param int $staleAfterSeconds age of the newest reading beyond which results are stale
     * @param array<string, CounterWidth> $widthsByMetric counter width per metric id, for sources that do not report it per sample
     */
    public function __construct(
        public int $maxGapSeconds,
        public int $staleAfterSeconds,
        private array $widthsByMetric = [],
    ) {
        if ($maxGapSeconds < 1 || $staleAfterSeconds < 1) {
            throw new \InvalidArgumentException('The maximum gap and the staleness threshold must be at least one second.');
        }
        foreach ($widthsByMetric as $width) {
            if (!$width instanceof CounterWidth) {
                throw new \InvalidArgumentException('Metric widths must be CounterWidth values.');
            }
        }
    }

    public function widthFor(string $metric): ?CounterWidth
    {
        return $this->widthsByMetric[$metric] ?? null;
    }
}
