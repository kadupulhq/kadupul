<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Evaluation;

use Kadupul\Alerting\Domain\Rule\ThresholdRule;

/**
 * Per-rule counters, open/closed state and the time of the last observation
 * counted. Ok, Breach and Hold enter only through advanceAt(), which counts an
 * observation only when it is newer than that time (ADR 0011 decision 5), so
 * two runs between polls cannot count one sample twice. A missing or stale
 * rate has no observation and enters through markUnknown().
 *
 * The constructor refuses any combination that no sequence of verdicts can
 * produce, so a stored row that was changed by hand is reported, not used.
 * Counters saturate at COUNTER_LIMIT, the largest value of their column.
 */
final class RuleState
{
    public const int COUNTER_LIMIT = 4_294_967_295;

    public function __construct(
        private bool $open = false,
        private int $consecutiveBreaches = 0,
        private int $consecutiveOks = 0,
        private bool $unknown = false,
        private ?int $lastObservedAt = null,
    ) {
        foreach ([$consecutiveBreaches, $consecutiveOks] as $counter) {
            if ($counter < 0 || $counter > self::COUNTER_LIMIT) {
                throw new InvalidRuleState('A consecutive count must be between 0 and ' . self::COUNTER_LIMIT . '.');
            }
        }
        if ($lastObservedAt !== null && $lastObservedAt < 0) {
            throw new InvalidRuleState('An observation time cannot be negative.');
        }
        if ($unknown && !$open) {
            throw new InvalidRuleState('Only an open incident can be unknown.');
        }
        if (!$open && $consecutiveOks > 0) {
            throw new InvalidRuleState('A closed rule has no recovery run.');
        }
        if ($consecutiveBreaches > 0 && $consecutiveOks > 0) {
            throw new InvalidRuleState('A breach run and an ok run cannot both be in progress.');
        }
        if ($lastObservedAt === null && ($open || $consecutiveBreaches > 0)) {
            throw new InvalidRuleState('Counts and an open incident need a counted observation.');
        }
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function consecutiveBreaches(): int
    {
        return $this->consecutiveBreaches;
    }

    public function consecutiveOks(): int
    {
        return $this->consecutiveOks;
    }

    /** True while an open incident's latest verdict is unknown. */
    public function isUnknown(): bool
    {
        return $this->unknown;
    }

    /** Unix seconds of the last observation counted, or null before the first. */
    public function lastObservedAt(): ?int
    {
        return $this->lastObservedAt;
    }

    public function isNewer(int $observedAt): bool
    {
        return $this->lastObservedAt === null || $observedAt > $this->lastObservedAt;
    }

    public function equals(self $other): bool
    {
        return $this->open === $other->open
            && $this->consecutiveBreaches === $other->consecutiveBreaches
            && $this->consecutiveOks === $other->consecutiveOks
            && $this->unknown === $other->unknown
            && $this->lastObservedAt === $other->lastObservedAt;
    }

    /**
     * Counts a verdict from an observation, unless that observation was
     * already counted or is older. Unknown has no observation: use markUnknown().
     */
    public function advanceAt(Verdict $verdict, ThresholdRule $rule, int $observedAt): Transition
    {
        if ($verdict === Verdict::Unknown) {
            throw new \InvalidArgumentException('An unknown verdict has no observation time; use markUnknown().');
        }
        if (!$this->isNewer($observedAt)) {
            return Transition::None;
        }
        $this->lastObservedAt = $observedAt;

        return $this->advance($verdict, $rule);
    }

    /** No usable rate: counters hold, and only an open incident reports it, once per run. */
    public function markUnknown(): Transition
    {
        if ($this->open && !$this->unknown) {
            $this->unknown = true;

            return Transition::BecameUnknown;
        }

        return Transition::None;
    }

    private function advance(Verdict $verdict, ThresholdRule $rule): Transition
    {
        $this->unknown = false;

        if ($verdict === Verdict::Hold) {
            return Transition::None;
        }

        if ($verdict === Verdict::Breach) {
            $this->consecutiveOks = 0;
            $this->consecutiveBreaches = min(self::COUNTER_LIMIT, $this->consecutiveBreaches + 1);
            if (!$this->open && $this->consecutiveBreaches >= $rule->forCycles) {
                $this->open = true;

                return Transition::Opened;
            }

            return Transition::None;
        }

        $this->consecutiveBreaches = 0;
        if (!$this->open) {
            return Transition::None;
        }

        $this->consecutiveOks = min(self::COUNTER_LIMIT, $this->consecutiveOks + 1);
        if ($this->consecutiveOks >= $rule->forCycles) {
            $this->open = false;
            $this->consecutiveOks = 0;

            return Transition::Recovered;
        }

        return Transition::None;
    }
}
