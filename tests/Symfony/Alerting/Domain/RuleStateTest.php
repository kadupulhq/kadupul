<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Domain;

use Kadupul\Alerting\Domain\Evaluation\InvalidRuleState;
use Kadupul\Alerting\Domain\Evaluation\RuleState;
use Kadupul\Alerting\Domain\Evaluation\Transition;
use Kadupul\Alerting\Domain\Evaluation\Verdict;
use Kadupul\Alerting\Domain\Rule\Comparator;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RuleStateTest extends TestCase
{
    private int $time = 1000;

    /** Counts one verdict from the next, newer observation. */
    private function feed(RuleState $state, Verdict $verdict, ThresholdRule $rule): Transition
    {
        return $verdict === Verdict::Unknown ? $state->markUnknown() : $state->advanceAt($verdict, $rule, ++$this->time);
    }

    /** Feed values through evaluate() and advanceAt(), the way the evaluator does. */
    private function step(RuleState $state, ThresholdRule $rule, ?float $value): Transition
    {
        return $this->feed($state, $rule->evaluate($value, $state->isOpen()), $rule);
    }

    public function testBreachOpensOnlyAfterForCycles(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 3);
        $state = new RuleState();

        self::assertSame(Transition::None, $this->feed($state, Verdict::Breach, $rule));
        self::assertSame(Transition::None, $this->feed($state, Verdict::Breach, $rule));
        self::assertFalse($state->isOpen());
        self::assertSame(Transition::Opened, $this->feed($state, Verdict::Breach, $rule));
        self::assertTrue($state->isOpen());
        self::assertSame(Transition::None, $this->feed($state, Verdict::Breach, $rule));
    }

    public function testAnOkVerdictResetsTheBreachRun(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 2);
        $state = new RuleState();

        $this->feed($state, Verdict::Breach, $rule);
        $this->feed($state, Verdict::Ok, $rule);

        self::assertSame(Transition::None, $this->feed($state, Verdict::Breach, $rule));
        self::assertSame(Transition::Opened, $this->feed($state, Verdict::Breach, $rule));
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
        $this->feed($state, Verdict::Breach, $rule);
        $this->feed($state, Verdict::Breach, $rule);

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
        // Unknown did not count as ok, but for_cycles is 1, so one real ok recovers.
        self::assertSame(Transition::Recovered, $this->step($state, $rule, 70.0));
    }

    public function testUnknownLeavesCountersAndClosedStateAlone(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 2);
        $state = new RuleState();

        $this->feed($state, Verdict::Breach, $rule);
        self::assertSame(Transition::None, $state->markUnknown());
        self::assertFalse($state->isUnknown(), 'Only an open incident is marked unknown.');
        self::assertSame(Transition::Opened, $this->feed($state, Verdict::Breach, $rule));
    }

    /** @return array{RuleState, ThresholdRule} */
    private function openIncident(int $forCycles): array
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, $forCycles);
        $state = new RuleState();
        for ($i = 0; $i < $forCycles; ++$i) {
            $this->feed($state, Verdict::Breach, $rule);
        }
        self::assertTrue($state->isOpen());

        return [$state, $rule];
    }

    public function testABreachResetsTheRecoveryRun(): void
    {
        [$state, $rule] = $this->openIncident(2);

        self::assertSame(Transition::None, $this->feed($state, Verdict::Ok, $rule));
        self::assertSame(Transition::None, $this->feed($state, Verdict::Breach, $rule));
        self::assertSame(Transition::None, $this->feed($state, Verdict::Ok, $rule));
        self::assertTrue($state->isOpen());
        self::assertSame(Transition::Recovered, $this->feed($state, Verdict::Ok, $rule));
    }

    public function testUnknownNeitherCountsAsOkNorResetsTheRecoveryRun(): void
    {
        // ADR 0011 decision 4: unknown does not change the consecutive counters.
        [$state, $rule] = $this->openIncident(2);

        self::assertSame(Transition::None, $this->feed($state, Verdict::Ok, $rule));
        self::assertSame(Transition::BecameUnknown, $state->markUnknown());
        self::assertSame(1, $state->consecutiveOks());
        self::assertSame(Transition::Recovered, $this->feed($state, Verdict::Ok, $rule));
    }

    public function testAHoldEndsAnUnknownRun(): void
    {
        [$state, $rule] = $this->openIncident(2);

        self::assertSame(Transition::BecameUnknown, $state->markUnknown());
        self::assertSame(Transition::None, $this->feed($state, Verdict::Hold, $rule));
        self::assertSame(Transition::BecameUnknown, $state->markUnknown());
    }

    public function testAnObservationNotNewerThanTheLastCountedIsIgnored(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 2);
        $state = new RuleState();

        self::assertTrue($state->isNewer(100));
        self::assertSame(Transition::None, $state->advanceAt(Verdict::Breach, $rule, 100));
        self::assertSame(100, $state->lastObservedAt());
        self::assertFalse($state->isNewer(100));
        self::assertSame(Transition::None, $state->advanceAt(Verdict::Breach, $rule, 100));
        self::assertSame(Transition::None, $state->advanceAt(Verdict::Breach, $rule, 99));
        self::assertSame(1, $state->consecutiveBreaches());
        self::assertSame(100, $state->lastObservedAt());
        self::assertSame(Transition::Opened, $state->advanceAt(Verdict::Breach, $rule, 101));
    }

    public function testUnknownLeavesTheLastObservationTime(): void
    {
        $state = new RuleState(true, 1, 0, false, 100);

        $state->markUnknown();

        self::assertSame(100, $state->lastObservedAt());
    }

    public function testAnUnknownVerdictNeedsMarkUnknownNotAnObservationTime(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 1);
        $state = new RuleState();

        try {
            $state->advanceAt(Verdict::Unknown, $rule, 100);
            self::fail('An unknown verdict was counted as an observation.');
        } catch (\InvalidArgumentException) {
            self::assertNull($state->lastObservedAt());
        }
    }

    public function testCountersSaturateInsteadOfOverflowingTheColumn(): void
    {
        $rule = new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 1);
        $state = new RuleState(true, RuleState::COUNTER_LIMIT, 0, false, 100);

        self::assertSame(Transition::None, $state->advanceAt(Verdict::Breach, $rule, 101));
        self::assertSame(RuleState::COUNTER_LIMIT, $state->consecutiveBreaches());
        self::assertSame(101, $state->lastObservedAt());
    }

    public function testEqualityComparesEveryField(): void
    {
        $state = new RuleState(true, 2, 0, true, 100);

        self::assertTrue($state->equals(new RuleState(true, 2, 0, true, 100)));
        self::assertFalse($state->equals(new RuleState(true, 2, 0, false, 100)));
        self::assertFalse($state->equals(new RuleState(true, 3, 0, true, 100)));
        self::assertFalse($state->equals(new RuleState(true, 2, 0, true, 101)));
        self::assertFalse($state->equals(new RuleState(false, 2, 0, false, 100)));
        self::assertFalse((new RuleState(true, 0, 1, false, 100))->equals(new RuleState(true, 0, 2, false, 100)));
    }

    /** @return iterable<string, array{bool, int, int, bool, ?int}> */
    public static function impossibleStates(): iterable
    {
        yield 'negative breach count' => [false, -1, 0, false, 100];
        yield 'negative ok count' => [true, 0, -1, false, 100];
        yield 'breach count above the column' => [true, RuleState::COUNTER_LIMIT + 1, 0, false, 100];
        yield 'ok count above the column' => [true, 0, RuleState::COUNTER_LIMIT + 1, false, 100];
        yield 'closed with an ok run' => [false, 0, 1, false, 100];
        yield 'unknown while closed' => [false, 0, 0, true, 100];
        yield 'breach and ok runs at once' => [true, 1, 1, false, 100];
        yield 'open with no observation' => [true, 1, 0, false, null];
        yield 'counted with no observation' => [false, 1, 0, false, null];
        yield 'negative observation time' => [false, 0, 0, false, -1];
    }

    #[DataProvider('impossibleStates')]
    public function testRefusesAStateNoVerdictSequenceCanReach(bool $open, int $breaches, int $oks, bool $unknown, ?int $lastObservedAt): void
    {
        $this->expectException(InvalidRuleState::class);
        new RuleState($open, $breaches, $oks, $unknown, $lastObservedAt);
    }
}
