<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Rule;

use Kadupul\Alerting\Domain\Evaluation\Verdict;

/**
 * A threshold with hysteresis. The trigger side uses the comparator exactly
 * (gt: value > trigger, ge: value >= trigger, lt and le mirrored). The clear
 * side is strict on the non-breaching side: an open incident clears only when
 * the value is below the clear threshold for gt/ge, or above it for lt/le. A
 * value equal to the clear threshold holds an open incident.
 */
final readonly class ThresholdRule
{
    public function __construct(
        public Comparator $comparator,
        public float $trigger,
        public float $clear,
        public int $forCycles,
    ) {
        if (!is_finite($trigger) || !is_finite($clear)) {
            throw new InvalidRule('Trigger and clear thresholds must be finite numbers.');
        }
        if ($forCycles < 1) {
            throw new InvalidRule('for_cycles must be at least 1.');
        }

        $clearOnWrongSide = $comparator->breachesUpward() ? $clear > $trigger : $clear < $trigger;
        if ($clearOnWrongSide) {
            throw new InvalidRule('The clear threshold must lie on the non-breaching side of the trigger threshold or equal it.');
        }
    }

    /**
     * @param bool $incidentOpen whether an incident is open for this rule; it
     *                           decides how a value between the thresholds counts
     */
    public function evaluate(?float $value, bool $incidentOpen = false): Verdict
    {
        if ($value === null || !is_finite($value)) {
            return Verdict::Unknown;
        }

        if ($this->breaches($value)) {
            return Verdict::Breach;
        }

        if (!$incidentOpen) {
            return Verdict::Ok;
        }

        return $this->hasCleared($value) ? Verdict::Ok : Verdict::Hold;
    }

    private function breaches(float $value): bool
    {
        return match ($this->comparator) {
            Comparator::GreaterThan => $value > $this->trigger,
            Comparator::GreaterOrEqual => $value >= $this->trigger,
            Comparator::LessThan => $value < $this->trigger,
            Comparator::LessOrEqual => $value <= $this->trigger,
        };
    }

    private function hasCleared(float $value): bool
    {
        return $this->comparator->breachesUpward() ? $value < $this->clear : $value > $this->clear;
    }
}
