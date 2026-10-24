<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Domain;

/**
 * Caps distinct series per metric. A label that carries an unbounded value
 * (a session id, a client address) would otherwise grow storage without limit,
 * so the guard fails loudly instead of dropping or truncating series.
 */
final class CardinalityGuard
{
    /** @var array<string, array<string, true>> */
    private array $admitted = [];

    public function __construct(private readonly int $limitPerMetric)
    {
        if ($limitPerMetric < 1) {
            throw new \InvalidArgumentException('The series limit per metric must be at least 1.');
        }
    }

    public function admit(string $metric, string $seriesKey): void
    {
        if (isset($this->admitted[$metric][$seriesKey])) {
            return;
        }
        if ($this->count($metric) >= $this->limitPerMetric) {
            throw new CardinalityLimitExceeded('Metric ' . $metric . ' already has ' . $this->limitPerMetric . ' series; refusing to admit another. Remove a high-cardinality label or raise the limit.');
        }
        $this->admitted[$metric][$seriesKey] = true;
    }

    public function count(string $metric): int
    {
        return count($this->admitted[$metric] ?? []);
    }
}
