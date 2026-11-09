<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Domain;

use Kadupul\Alerting\Domain\Evaluation\RuleState;
use Kadupul\Alerting\Domain\Evaluation\Transition;
use Kadupul\Alerting\Domain\Evaluation\Verdict;
use Kadupul\Alerting\Domain\Rule\Comparator;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;
use PHPUnit\Framework\TestCase;

final class RuleStateTest extends TestCase
{
    /** Feed values through evaluate() and advance(), the way the evaluator will. */
    private function step(RuleState $state, ThresholdRule $rule, ?float $value): Transition
    {
        return $state->advance($rule->evaluate($value, $state->isOpen()), $rule);
    }

    public function testBreachOpensOnlyAfterForCycles(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 3);
        $state = new RuleState();

        self::assertSame(Transition::None, $state->advance(Verdict::Breach, $rule));
        self::assertSame(Transition::None, $state->advance(Verdict::Breach, $rule));
        self::assertFalse($state->isOpen());
        self::assertSame(Transition::Opened, $state->advance(Verdict::Breach, $rule));
        self::assertTrue($state->isOpen());
        self::assertSame(Transition::None, $state->advance(Verdict::Breach, $rule));
    }

    public function testAnOkVerdictResetsTheBreachRun(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 2);
        $state = new RuleState();

        $state->advance(Verdict::Breach, $rule);
        $state->advance(Verdict::Ok, $rule);

        self::assertSame(Transition::None, $state->advance(Verdict::Breach, $rule));
        self::assertSame(Transition::Opened, $state->advance(Verdict::Breach, $rule));
    }

    public function testHysteresisHoldsOpenBetweenClearAndTrigger(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 1);
        $state = new RuleState();

        self::assertSame(Transition::Opened, $this->step($state, $rule, 95.0));
        self::assertSame(Transition::None, $this->step($state, $rule, 85.0));
        self::assertTrue($state->isOpen());
        self::assertSame(Transition::Recovered, $this->step($state, $rule, 70.0));
        self::assertFalse($state->isOpen());
    }

    public function testRecoveryNeedsForCyclesConsecutiveOkVerdicts(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 2);
        $state = new RuleState();
        $state->advance(Verdict::Breach, $rule);
        $state->advance(Verdict::Breach, $rule);

        self::assertSame(Transition::None, $this->step($state, $rule, 70.0));
        // A value between the thresholds holds the counters rather than resetting them.
        self::assertSame(Transition::None, $this->step($state, $rule, 85.0));
        self::assertSame(Transition::Recovered, $this->step($state, $rule, 70.0));
    }

    public function testFlappingAroundThresholdOpensOnce(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 1);
        $state = new RuleState();
        $transitions = [];

        foreach ([91.0, 89.0, 91.0, 89.0, 91.0] as $value) {
            $transitions[] = $this->step($state, $rule, $value);
        }

        self::assertSame(1, count(array_filter($transitions, static fn(Transition $t): bool => $t === Transition::Opened)));
        self::assertNotContains(Transition::Recovered, $transitions);
    }

    public function testUnknownNeverRecoversAnOpenIncident(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 1);
        $state = new RuleState();
        $this->step($state, $rule, 95.0);

        self::assertSame(Transition::BecameUnknown, $this->step($state, $rule, null));
        self::assertTrue($state->isOpen());
        // Still unknown: reported once, not on every cycle.
        self::assertSame(Transition::None, $this->step($state, $rule, null));
        self::assertTrue($state->isOpen());
        // Unknown did not count as ok, so the first real ok still needs for_cycles.
        self::assertSame(Transition::Recovered, $this->step($state, $rule, 70.0));
    }

    public function testUnknownLeavesCountersAndClosedStateAlone(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 2);
        $state = new RuleState();

        $state->advance(Verdict::Breach, $rule);
        self::assertSame(Transition::None, $state->advance(Verdict::Unknown, $rule));
        self::assertSame(Transition::Opened, $state->advance(Verdict::Breach, $rule));
    }
}
