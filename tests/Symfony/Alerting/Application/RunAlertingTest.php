<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Application;

use Kadupul\Alerting\Application\AlertingConfiguration;
use Kadupul\Alerting\Application\AlertingRunOutcome;
use Kadupul\Alerting\Application\DeliveryReport;
use Kadupul\Alerting\Application\EvaluateRules;
use Kadupul\Alerting\Application\Port\RunLock;
use Kadupul\Alerting\Application\RunAlerting;
use Kadupul\Alerting\Domain\Evaluation\Observation;
use Kadupul\Alerting\Domain\Rule\AlertRule;
use Kadupul\Alerting\Domain\Rule\Comparator;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;
use Kadupul\Tests\Alerting\Fakes\InMemoryBaselineSource;
use Kadupul\Tests\Alerting\Fakes\FixedAlertingSettings;
use Kadupul\Tests\Alerting\Fakes\InMemoryEvaluationCursor;
use Kadupul\Tests\Alerting\Fakes\InMemoryIncidentRepository;
use Kadupul\Tests\Alerting\Fakes\InMemoryNotificationQueue;
use Kadupul\Tests\Alerting\Fakes\InMemoryRateSource;
use Kadupul\Tests\Alerting\Fakes\InMemoryRuleRepository;
use Kadupul\Tests\Alerting\Fakes\InMemoryRuleStateStore;
use Kadupul\Tests\Alerting\Fakes\InMemoryRunLock;
use Kadupul\Tests\Alerting\Fakes\InMemoryTransactionRunner;
use Kadupul\Tests\Alerting\Fakes\MockClockAdapter;
use Kadupul\Tests\Alerting\Fakes\RecordingLogger;
use Kadupul\Tests\Alerting\Fakes\RecordingNotificationDrain;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RunAlertingTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    private MockClock $clock;
    private InMemoryRateSource $rates;
    private InMemoryRuleRepository $rules;
    private InMemoryRuleStateStore $states;
    private InMemoryIncidentRepository $incidents;
    private InMemoryEvaluationCursor $cursor;
    private FixedAlertingSettings $settings;
    private InMemoryRunLock $lock;
    private RecordingNotificationDrain $drain;
    private RecordingLogger $logger;
    private InMemoryBaselineSource $baselines;

    protected function setUp(): void
    {
        $this->clock = new MockClock(new \DateTimeImmutable('@' . self::NOW));
        $this->rates = new InMemoryRateSource(clock: $this->clock);
        $this->states = new InMemoryRuleStateStore();
        $this->incidents = new InMemoryIncidentRepository();
        $this->rules = new InMemoryRuleRepository($this->states, $this->incidents);
        $this->cursor = new InMemoryEvaluationCursor();
        $this->settings = new FixedAlertingSettings(new AlertingConfiguration(true, 100, 30.0));
        $this->lock = new InMemoryRunLock();
        $this->drain = new RecordingNotificationDrain();
        $this->logger = new RecordingLogger();
        $this->baselines = new InMemoryBaselineSource();
        foreach ([1, 2, 3] as $id) {
            $this->rules->add(new AlertRule($id, new SeriesKey(100 + $id, 'traffic_in'), new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 1)));
            $this->rates->put(new SeriesKey(100 + $id, 'traffic_in'), new Observation(50.0, self::NOW, 300));
        }
    }

    private function runOnce(?int $batchSize = null, ?float $budgetSeconds = null, int $deliveryBatchSize = RunAlerting::DELIVERY_BATCH_SIZE): \Kadupul\Alerting\Application\AlertingRun
    {
        $notifications = new InMemoryNotificationQueue($this->incidents);
        $clock = new MockClockAdapter($this->clock);
        $evaluate = new EvaluateRules(
            $this->rates,
            $this->baselines,
            $this->rules,
            $this->states,
            $this->incidents,
            $notifications,
            $this->cursor,
            $clock,
            new InMemoryTransactionRunner($this->states, $this->incidents, $notifications),
            $this->logger,
        );

        return (new RunAlerting($this->settings, $this->lock, $evaluate, $this->drain, $clock, $this->logger))($batchSize, $budgetSeconds, $deliveryBatchSize);
    }

    public function testDisabledAlertingEvaluatesNothingSendsNothingAndTakesNoLock(): void
    {
        $this->settings->configuration = new AlertingConfiguration(false);

        $run = $this->runOnce();

        self::assertSame(AlertingRunOutcome::Disabled, $run->outcome);
        self::assertNull($run->evaluation);
        self::assertNull($run->delivery);
        self::assertSame([], $this->baselines->prunes, 'Disabled alerting removes no baseline history either.');
        self::assertSame([], $this->lock->events);
        self::assertSame([], $this->rates->requests);
        self::assertSame([], $this->drain->calls, 'No mail is sent while alerting is off (ADR 0011 decision 10).');
    }

    public function testEvaluatesWithTheStoredBatchSizeAndBudget(): void
    {
        // A zero budget stops after the first batch, so the cursor shows the batch size used.
        $this->settings->configuration = new AlertingConfiguration(true, 2, 0.0);

        $run = $this->runOnce();

        self::assertSame(AlertingRunOutcome::Evaluated, $run->outcome);
        self::assertSame(2, $run->evaluation?->evaluated);
        self::assertSame(2, $this->cursor->position());
        self::assertSame([[RunAlerting::DELIVERY_BATCH_SIZE, 0.0]], $this->drain->calls);
    }

    public function testExplicitValuesOverrideTheStoredSettings(): void
    {
        $this->settings->configuration = new AlertingConfiguration(true, 2, 0.0);

        $run = $this->runOnce(1, 0.0, 7);

        self::assertSame(1, $run->evaluation?->evaluated);
        self::assertSame(1, $this->cursor->position());
        self::assertSame([[7, 0.0]], $this->drain->calls);
    }

    public function testASecondRunWhileAnotherEvaluatesExitsWithoutEvaluating(): void
    {
        $this->lock->elsewhere = [RunLock::EVALUATE];

        $run = $this->runOnce();

        self::assertSame(AlertingRunOutcome::Busy, $run->outcome);
        self::assertNull($run->evaluation);
        self::assertSame([], $this->rates->requests);
        self::assertSame([], $this->states->saved);
        self::assertSame([], $this->drain->calls, 'The run that holds the lock drains after it evaluates.');
        self::assertSame(['busy evaluate'], $this->lock->events, 'A lock this run did not take is not released.');
    }

    public function testAFailedEvaluationStillDrainsTheQueueAndThenPropagates(): void
    {
        $this->rates->failure = new \RuntimeException('lost connection');
        $this->drain->report = new DeliveryReport(sent: 1);

        try {
            $this->runOnce(budgetSeconds: 30.0);
            self::fail('The failed rate read was not reported.');
        } catch (\RuntimeException $e) {
            self::assertSame('lost connection', $e->getMessage());
        }

        self::assertSame(['acquire evaluate', 'release evaluate', 'acquire deliver', 'release deliver'], $this->lock->events);
        self::assertSame([[RunAlerting::DELIVERY_BATCH_SIZE, 30.0]], $this->drain->calls, 'Notifications already decided are still sent.');
        self::assertSame([], $this->logger->records);
    }

    public function testAFailedEvaluationDrainsNothingWhileAnotherDelivers(): void
    {
        $this->rates->failure = new \RuntimeException('lost connection');
        $this->lock->elsewhere = [RunLock::DELIVER];

        try {
            $this->runOnce();
            self::fail('The failed rate read was not reported.');
        } catch (\RuntimeException $e) {
            self::assertSame('lost connection', $e->getMessage());
        }

        self::assertSame(['acquire evaluate', 'release evaluate', 'busy deliver'], $this->lock->events);
        self::assertSame([], $this->drain->calls);
    }

    public function testADeliveryFailureAfterAFailedEvaluationIsLoggedAndTheEvaluationErrorPropagates(): void
    {
        $this->rates->failure = new \RuntimeException('lost connection');
        $this->drain->failure = new \LogicException('SELECT secret FROM worker');

        try {
            $this->runOnce();
            self::fail('The failed rate read was not reported.');
        } catch (\RuntimeException $e) {
            self::assertSame('lost connection', $e->getMessage(), 'The evaluation error is the one reported.');
        }

        self::assertSame(['acquire evaluate', 'release evaluate', 'acquire deliver', 'release deliver'], $this->lock->events);
        self::assertSame([[
            'level' => 'error',
            'message' => 'Queued alert notifications were not delivered after the failed evaluation: {error}',
            'context' => ['error' => \LogicException::class],
        ]], $this->logger->records, 'The class only: no message or SQL.');
    }

    public function testTheEvaluationLockIsReleasedBeforeDelivery(): void
    {
        $held = null;
        $this->drain->during = function () use (&$held): void {
            $held = array_keys($this->lock->held);
        };

        $this->runOnce();

        // Slow mail never holds back the next evaluation.
        self::assertSame([RunLock::DELIVER], $held);
        self::assertSame(['acquire evaluate', 'release evaluate', 'acquire deliver', 'release deliver'], $this->lock->events);
    }

    public function testTheDeliveryLockIsReleasedWhenDeliveryFails(): void
    {
        $this->drain->failure = new \RuntimeException('worker failed');

        try {
            $this->runOnce();
            self::fail('The failed delivery was not reported.');
        } catch (\RuntimeException $e) {
            self::assertSame('worker failed', $e->getMessage());
        }

        self::assertSame(['acquire evaluate', 'release evaluate', 'acquire deliver', 'release deliver'], $this->lock->events);
    }

    public function testDeliveryGetsWhatIsLeftOfTheBudget(): void
    {
        $this->rates = new InMemoryRateSource(clock: $this->clock, secondsPerRead: 12.5);
        $this->rates->put(new SeriesKey(101, 'traffic_in'), new Observation(50.0, self::NOW, 300));
        $this->settings->configuration = new AlertingConfiguration(true, 100, 30.0);

        $this->runOnce();

        self::assertSame([[RunAlerting::DELIVERY_BATCH_SIZE, 17.5]], $this->drain->calls);
    }

    public function testDeliveryGetsZeroWhenEvaluationUsedTheWholeBudget(): void
    {
        $this->rates = new InMemoryRateSource(clock: $this->clock, secondsPerRead: 45.0);
        $this->settings->configuration = new AlertingConfiguration(true, 100, 30.0);

        $this->runOnce();

        // DeliverNotifications still runs its first batch, bounded by the delivery batch size.
        self::assertSame([[RunAlerting::DELIVERY_BATCH_SIZE, 0.0]], $this->drain->calls);
    }

    public function testQueuedNotificationsAreStillDeliveredWhileTheRateSourceIsUnavailable(): void
    {
        $this->rates->available = false;

        $run = $this->runOnce();

        self::assertSame(AlertingRunOutcome::Evaluated, $run->outcome);
        self::assertSame(EvaluateRules::RATE_SOURCE_UNAVAILABLE, $run->evaluation?->inactiveReason);
        self::assertCount(1, $this->drain->calls);
    }

    public function testABusyDelivererLeavesTheQueueToIt(): void
    {
        $this->lock->elsewhere = [RunLock::DELIVER];

        $run = $this->runOnce();

        self::assertSame(AlertingRunOutcome::Evaluated, $run->outcome);
        self::assertSame(3, $run->evaluation?->evaluated);
        self::assertNull($run->delivery);
        self::assertSame([], $this->drain->calls);
    }

    public function testTheDeliveryReportIsReturned(): void
    {
        $this->drain->report = new DeliveryReport(sent: 2, retrying: 1);

        self::assertSame($this->drain->report, $this->runOnce()->delivery);
    }

    public function testALockThatCouldNotBeReleasedIsLogged(): void
    {
        $this->lock->releaseFails = true;

        $this->runOnce();

        self::assertSame([
            ['level' => 'warning', 'message' => 'The alerting {lock} lock was not held at release; it ends with the database session.', 'context' => ['lock' => 'evaluate']],
            ['level' => 'warning', 'message' => 'The alerting {lock} lock was not held at release; it ends with the database session.', 'context' => ['lock' => 'deliver']],
        ], $this->logger->records);
    }
}
