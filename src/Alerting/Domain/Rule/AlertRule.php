<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Rule;

/**
 * A stored rule: its id (the evaluation cursor orders by it), the series it
 * watches and its condition, a fixed threshold or an hour-of-week baseline.
 */
final readonly class AlertRule
{
    public function __construct(
        public int $id,
        public SeriesKey $series,
        public ThresholdRule|BaselineRule $condition,
    ) {
        if ($id < 1) {
            throw new InvalidRule('A rule id must be at least 1.');
        }
    }

    /** The same rule watching the same series with the same condition. */
    public function equals(self $other): bool
    {
        return $this->id === $other->id
            && $this->series->localDataId === $other->series->localDataId
            && $this->series->rrdName === $other->series->rrdName
            && match (true) {
                $this->condition instanceof ThresholdRule => $other->condition instanceof ThresholdRule && $this->condition->equals($other->condition),
                default => $other->condition instanceof BaselineRule && $this->condition->equals($other->condition),
            };
    }
}
