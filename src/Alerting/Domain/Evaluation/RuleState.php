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
 * counted. advanceAt() counts an observation only when it is newer than that
 * time (ADR 0011 decision 5), so two runs between polls cannot count one
 * sample twice. advance() is for verdicts with no observation, which is
 * unknown.
 */
final class RuleState
{
    public function __construct(
        private bool $open = false,
        private int $consecutiveBreaches = 0,
        private int $consecutiveOks = 0,
        private bool $unknown = false,
        private ?int $lastObservedAt = null,
    ) {}

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

    /** Counts a verdict from an observation, unless that observation was already counted or is older. */
    public function advanceAt(Verdict $verdict, ThresholdRule $rule, int $observedAt): Transition
    {
        if (!$this->isNewer($observedAt)) {
            return Transition::None;
        }
        $this->lastObservedAt = $observedAt;

        return $this->advance($verdict, $rule);
    }

    public function advance(Verdict $verdict, ThresholdRule $rule): Transition
    {
        if ($verdict === Verdict::Unknown) {
            // Counters hold. Only an open incident reports it, and only once.
            if ($this->open && !$this->unknown) {
                $this->unknown = true;

                return Transition::BecameUnknown;
            }

            return Transition::None;
        }

        $this->unknown = false;

        if ($verdict === Verdict::Hold) {
            return Transition::None;
        }

        if ($verdict === Verdict::Breach) {
            $this->consecutiveOks = 0;
            ++$this->consecutiveBreaches;
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

        ++$this->consecutiveOks;
        if ($this->consecutiveOks >= $rule->forCycles) {
            $this->open = false;
            $this->consecutiveOks = 0;

            return Transition::Recovered;
        }

        return Transition::None;
    }
}
