<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Application;

use Kadupul\Alerting\Application\EvaluateRules;
use Kadupul\Alerting\Domain\Evaluation\Observation;
use Kadupul\Alerting\Domain\Evaluation\RuleState;
use Kadupul\Alerting\Domain\Rule\AlertRule;
use Kadupul\Alerting\Domain\Rule\Comparator;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;
use Kadupul\Tests\Alerting\Fakes\InMemoryEvaluationCursor;
use Kadupul\Tests\Alerting\Fakes\InMemoryIncidentRepository;
use Kadupul\Tests\Alerting\Fakes\InMemoryRateSource;
use Kadupul\Tests\Alerting\Fakes\InMemoryRuleRepository;
use Kadupul\Tests\Alerting\Fakes\InMemoryRuleStateStore;
use Kadupul\Tests\Alerting\Fakes\InMemoryTransactionRunner;
use Kadupul\Tests\Alerting\Fakes\MockClockAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class EvaluateRulesTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    private MockClock $clock;
    private InMemoryRateSource $rates;
    private InMemoryRuleRepository $rules;
    private InMemoryRuleStateStore $states;
    private InMemoryIncidentRepository $incidents;
    private InMemoryEvaluationCursor $cursor;
    private InMemoryTransactionRunner $transactions;

    protected function setUp(): void
    {
        $this->clock = new MockClock(new \DateTimeImmutable('@' . self::NOW));
        $this->rates = new InMemoryRateSource(clock: $this->clock);
        $this->rules = new InMemoryRuleRepository();
        $this->states = new InMemoryRuleStateStore();
        $this->incidents = new InMemoryIncidentRepository();
        $this->cursor = new InMemoryEvaluationCursor();
        $this->transactions = new InMemoryTransactionRunner($this->states, $this->incidents);
    }

    private function evaluator(): EvaluateRules
    {
        return new EvaluateRules($this->rates, $this->rules, $this->states, $this->incidents, $this->cursor, new MockClockAdapter($this->clock), $this->transactions);
    }

    private static function rule(int $id, Comparator $comparator = Comparator::GreaterThan, float $trigger = 90.0, float $clear = 80.0, int $forCycles = 1): AlertRule
    {
        return new AlertRule($id, new SeriesKey(100 + $id, 'traffic_in'), new ThresholdRule($comparator, $trigger, $clear, $forCycles));
    }

    public function testInactiveWhenRateSourceUnavailable(): void
    {
        $this->rates->available = false;
        $this->rules->add(self::rule(1), self::rule(2));
        $this->rates->put(new SeriesKey(101, 'traffic_in'), new Observation(95.0, self::NOW, 300));

        $report = $this->evaluator()(10, 30.0);

        self::assertSame(EvaluateRules::RATE_SOURCE_UNAVAILABLE, $report->inactiveReason);
        self::assertSame('rate_source_unavailable', $report->inactiveReason);
        self::assertSame(0, $report->evaluated);
        self::assertSame(0, $report->skipped);
        self::assertSame([], $this->states->saved, 'An inactive source must write no rule state.');
        self::assertSame([], $this->incidents->events);
        self::assertSame([], $this->rates->requests, 'No rate is read while the source is unavailable.');
        self::assertSame(0, $this->cursor->position());
    }

    public function testMissingRowIsUnknownNotZero(): void
    {
        // A missing rate read as 0 would breach "lt 1" at once and open an incident.
        $this->rules->add(self::rule(1, Comparator::LessThan, 1.0, 2.0));
        // An open incident with counters: unknown must neither close it nor reset them.
        $this->rules->add(self::rule(2));
        $this->states->states[2] = new RuleState(open: true, consecutiveOks: 1, lastObservedAt: self::NOW - 900);

        $report = $this->evaluator()(10, 30.0);

        self::assertNull($report->inactiveReason);
        self::assertSame(2, $report->evaluated);
        self::assertSame([], $this->incidents->events);
        self::assertArrayNotHasKey(1, $this->states->states, 'An unknown verdict that changes nothing writes no state.');
        self::assertTrue($this->states->states[2]->isOpen());
        self::assertTrue($this->states->states[2]->isUnknown());
        self::assertSame(1, $this->states->states[2]->consecutiveOks());
    }

    public function testAStaleObservationIsUnknown(): void
    {
        $this->rules->add(self::rule(1, Comparator::LessThan, 1.0, 2.0));
        $series = new SeriesKey(101, 'traffic_in');

        // Older than two intervals of a 300-second step: not fresh.
        $this->rates->put($series, new Observation(0.0, self::NOW - 601, 300));
        $this->evaluator()(10, 30.0);
        self::assertSame([], $this->incidents->events);
        self::assertArrayNotHasKey(1, $this->states->states);

        // Exactly two intervals old is still fresh.
        $this->rates->put($series, new Observation(0.0, self::NOW - 600, 300));
        $this->evaluator()(10, 30.0);
        self::assertSame([['open', 1, self::NOW - 600]], $this->incidents->events);
    }

    public function testTheSameObservationTimeCountsOnce(): void
    {
        $this->rules->add(self::rule(1, forCycles: 2));
        $series = new SeriesKey(101, 'traffic_in');
        $this->rates->put($series, new Observation(95.0, self::NOW - 10, 300));

        $first = $this->evaluator()(10, 30.0);
        $second = $this->evaluator()(10, 30.0);

        self::assertSame([1, 0], [$first->evaluated, $first->skipped]);
        self::assertSame([0, 1], [$second->evaluated, $second->skipped]);
        self::assertSame(1, $this->states->states[1]->consecutiveBreaches(), 'One sample must not count twice.');
        self::assertSame(self::NOW - 10, $this->states->states[1]->lastObservedAt());
        self::assertSame([], $this->incidents->events);

        // An older observation, such as one replayed after recovery, is ignored too.
        $this->rates->put($series, new Observation(95.0, self::NOW - 20, 300));
        $this->evaluator()(10, 30.0);
        self::assertSame(1, $this->states->states[1]->consecutiveBreaches());

        $this->rates->put($series, new Observation(95.0, self::NOW, 300));
        $this->evaluator()(10, 30.0);
        self::assertSame([['open', 1, self::NOW]], $this->incidents->events);
    }

    public function testRecoveryClosesTheIncident(): void
    {
        $this->rules->add(self::rule(1));
        $series = new SeriesKey(101, 'traffic_in');
        $this->rates->put($series, new Observation(95.0, self::NOW - 300, 300));
        $this->evaluator()(10, 30.0);
        $this->rates->put($series, new Observation(85.0, self::NOW - 200, 300));
        $this->evaluator()(10, 30.0);
        $this->rates->put($series, new Observation(70.0, self::NOW - 100, 300));
        $this->evaluator()(10, 30.0);

        self::assertSame([['open', 1, self::NOW - 300], ['close', 1, self::NOW - 100]], $this->incidents->events);
    }

    public function testRulesSharingASeriesReadItOnce(): void
    {
        $series = new SeriesKey(101, 'traffic_in');
        $this->rules->add(
            new AlertRule(1, $series, new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 1)),
            new AlertRule(2, $series, new ThresholdRule(Comparator::LessThan, 10.0, 20.0, 1)),
        );
        $this->rates->put($series, new Observation(95.0, self::NOW, 300));

        $this->evaluator()(10, 30.0);

        self::assertSame([['101:traffic_in']], $this->rates->requests);
        self::assertSame([['open', 1, self::NOW]], $this->incidents->events);
    }

    public function testBudgetStopsBatchAndResumesNextRunByRuleId(): void
    {
        // Ids are not contiguous: the cursor is an id, not an offset.
        foreach ([3, 5, 8, 13, 21] as $id) {
            $this->rules->add(self::rule($id));
        }
        // Each batch read costs 0.6 s against a 1 s budget, checked between batches.
        $this->rates = new InMemoryRateSource(clock: $this->clock, secondsPerRead: 0.6);

        $first = $this->evaluator()(2, 1.0);

        self::assertSame(4, $first->evaluated, 'Two batches fit: the budget is checked between batches.');
        self::assertSame([['103:traffic_in', '105:traffic_in'], ['108:traffic_in', '113:traffic_in']], $this->rates->requests);
        self::assertSame(13, $this->cursor->position());

        $second = $this->evaluator()(2, 1.0);

        self::assertSame(1, $second->evaluated);
        self::assertSame(0, $this->cursor->position(), 'A completed pass starts the next run from the first rule.');
        self::assertSame([['103:traffic_in', '105:traffic_in'], ['108:traffic_in', '113:traffic_in'], ['121:traffic_in']], $this->rates->requests, 'No rule is evaluated twice across calls.');

        $this->evaluator()(2, 1.0);
        self::assertSame(['103:traffic_in', '105:traffic_in'], $this->rates->requests[3]);
        self::assertSame(['108:traffic_in', '113:traffic_in'], $this->rates->requests[4]);
        self::assertCount(5, $this->rates->requests);
    }

    public function testAnExactlyFullLastBatchEndsThePass(): void
    {
        $this->rules->add(self::rule(1), self::rule(2));

        $report = $this->evaluator()(2, 30.0);

        self::assertSame(2, $report->evaluated);
        self::assertSame(0, $this->cursor->position());
        self::assertSame([['101:traffic_in', '102:traffic_in']], $this->rates->requests);
    }

    public function testInvalidLimitsAreRefusedBeforeAnyRead(): void
    {
        foreach ([[0, 1.0], [1, -1.0], [1, NAN], [1, INF]] as [$batch, $budget]) {
            try {
                $this->evaluator()($batch, $budget);
                self::fail('Accepted batch ' . $batch . ' and budget ' . $budget);
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->rates->requests);
            }
        }
    }

    public function testAnUnchangedUnknownWritesNoState(): void
    {
        $this->rules->add(self::rule(1));
        $this->states->states[1] = new RuleState(open: true, consecutiveBreaches: 1, lastObservedAt: self::NOW - 900);

        $this->evaluator()(10, 30.0);
        self::assertSame([1], $this->states->saved, 'Becoming unknown is a change and is saved once.');
        self::assertTrue($this->states->states[1]->isUnknown());

        $report = $this->evaluator()(10, 30.0);
        self::assertSame(1, $report->evaluated);
        self::assertSame([1], $this->states->saved, 'A rule that stays unknown is not written again.');
        self::assertSame(1, $this->transactions->committed);
    }

    public function testARateSourceFailurePropagatesInsteadOfReadingAsUnknown(): void
    {
        $this->rules->add(self::rule(1));
        $this->states->states[1] = new RuleState(open: true, consecutiveBreaches: 1, lastObservedAt: self::NOW - 900);
        $this->cursor->moveTo(0);
        $this->rates->failure = new \RuntimeException('dsstats read failed');

        try {
            $this->evaluator()(10, 30.0);
            self::fail('A failed rate read was not reported.');
        } catch (\RuntimeException $e) {
            self::assertSame('dsstats read failed', $e->getMessage());
        }
        self::assertSame([], $this->states->saved);
        self::assertFalse($this->states->states[1]->isUnknown(), 'A failed read is not an unknown verdict.');
        self::assertSame([], $this->incidents->events);
        self::assertSame(0, $this->cursor->position());
    }

    public function testAFailedIncidentWriteLeavesTheRuleStateAsItWas(): void
    {
        $this->rules->add(self::rule(1));
        $this->rates->put(new SeriesKey(101, 'traffic_in'), new Observation(95.0, self::NOW, 300));
        $this->incidents->failure = new \RuntimeException('incident insert failed');

        try {
            $this->evaluator()(10, 30.0);
            self::fail('A failed incident write was not reported.');
        } catch (\RuntimeException $e) {
            self::assertSame('incident insert failed', $e->getMessage());
        }
        // The state was saved first, inside the same transaction, and rolled back with it.
        self::assertArrayNotHasKey(1, $this->states->states);
        self::assertSame([], $this->incidents->events);
        self::assertSame(0, $this->transactions->committed);

        $this->incidents->failure = null;
        $this->evaluator()(10, 30.0);
        self::assertSame([['open', 1, self::NOW]], $this->incidents->events, 'The same observation opens the incident on the retry.');
    }

    public function testAnOrphanedRuleEndsItsIncidentAsOrphanedAndForgetsItsState(): void
    {
        $this->rules->add(self::rule(1), self::rule(2));
        $this->states->states[1] = new RuleState(open: true, consecutiveBreaches: 1, lastObservedAt: self::NOW - 900);
        $this->incidents->active[1] = true;
        $this->rules->orphaned = [1];
        $this->rates->put(new SeriesKey(102, 'traffic_in'), new Observation(95.0, self::NOW, 300));

        $report = $this->evaluator()(10, 30.0);

        self::assertSame(1, $report->orphaned);
        self::assertSame([['orphan', 1, self::NOW], ['open', 2, self::NOW]], $this->incidents->events);
        self::assertArrayNotHasKey(1, $this->states->states);
        self::assertSame(2, $this->transactions->committed, 'One transaction for the orphan, one for rule 2.');
        self::assertNotContains('close', array_column($this->incidents->events, 0), 'An orphaned incident is not a recovery.');
    }

    public function testAnObservationTooFarAheadOfTheClockIsUnknown(): void
    {
        $this->rules->add(self::rule(1));
        $series = new SeriesKey(101, 'traffic_in');

        $this->rates->put($series, new Observation(95.0, self::NOW + 61, 300));
        $this->evaluator()(10, 30.0);
        self::assertSame([], $this->incidents->events);
        self::assertArrayNotHasKey(1, $this->states->states);

        $this->rates->put($series, new Observation(95.0, self::NOW + 60, 300));
        $this->evaluator()(10, 30.0);
        self::assertSame([['open', 1, self::NOW + 60]], $this->incidents->events);
    }
}
