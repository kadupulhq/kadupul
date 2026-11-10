<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Infrastructure;

use Doctrine\DBAL\DriverManager;
use Kadupul\Alerting\Application\AlertingConfiguration;
use Kadupul\Alerting\Application\DeliverNotifications;
use Kadupul\Alerting\Application\DeliveryReport;
use Kadupul\Alerting\Application\EvaluateRules;
use Kadupul\Alerting\Application\Port\AlertingSettings;
use Kadupul\Alerting\Application\Port\RunLock;
use Kadupul\Alerting\Application\RunAlerting;
use Kadupul\Alerting\Domain\Evaluation\Observation;
use Kadupul\Alerting\Domain\Rule\AlertRule;
use Kadupul\Alerting\Domain\Rule\Comparator;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;
use Kadupul\Alerting\Infrastructure\Dbal\DbalAlertingSettings;
use Kadupul\Alerting\Infrastructure\Symfony\EvaluateCommand;
use Kadupul\Kernel;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

final class EvaluateCommandTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    private MockClock $clock;
    private InMemoryRateSource $rates;
    private InMemoryRuleRepository $rules;
    private InMemoryRuleStateStore $states;
    private InMemoryRunLock $lock;
    private RecordingNotificationDrain $drain;
    private RecordingLogger $logger;
    private AlertingSettings $settings;

    protected function setUp(): void
    {
        $this->clock = new MockClock(new \DateTimeImmutable('@' . self::NOW));
        $this->rates = new InMemoryRateSource(clock: $this->clock);
        $this->states = new InMemoryRuleStateStore();
        $this->rules = new InMemoryRuleRepository($this->states);
        $this->lock = new InMemoryRunLock();
        $this->drain = new RecordingNotificationDrain(new DeliveryReport(sent: 1));
        $this->logger = new RecordingLogger();
        $this->settings = new FixedAlertingSettings(new AlertingConfiguration(true, 100, 30.0));
        foreach ([1, 2] as $id) {
            $this->rules->add(new AlertRule($id, new SeriesKey(100 + $id, 'traffic_in'), new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 1)));
            $this->rates->put(new SeriesKey(100 + $id, 'traffic_in'), new Observation(95.0, self::NOW, 300));
        }
    }

    private function tester(): CommandTester
    {
        $incidents = new InMemoryIncidentRepository();
        $notifications = new InMemoryNotificationQueue($incidents);
        $clock = new MockClockAdapter($this->clock);
        $evaluate = new EvaluateRules($this->rates, $this->rules, $this->states, $incidents, $notifications, new InMemoryEvaluationCursor(), $clock, new InMemoryTransactionRunner($this->states, $incidents, $notifications), $this->logger);

        return new CommandTester(new EvaluateCommand(new RunAlerting($this->settings, $this->lock, $evaluate, $this->drain, $clock, $this->logger), $this->logger));
    }

    public function testExitsZeroAfterEvaluating(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]));
        self::assertSame(
            "Alert rules: 2 evaluated, 0 already counted, 0 skipped as unusable, 0 orphaned.\nNotifications: 1 sent, 0 retrying, 0 failed, 0 suppressed, 0 lost.\n",
            $tester->getDisplay(),
        );
        self::assertCount(2, $this->states->saved);
    }

    public function testExitsZeroWithAnInactiveMessageWhenAlertingIsOff(): void
    {
        $this->settings = new FixedAlertingSettings(new AlertingConfiguration(false));
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]));
        self::assertSame("inactive: alerting is disabled (alerting_enable is off).\n", $tester->getDisplay());
        self::assertSame([], $this->rates->requests);
        self::assertSame([], $this->lock->events);
        self::assertSame([], $this->drain->calls);
    }

    public function testExitsTwoWhenTheRateSourceIsUnavailable(): void
    {
        $this->rates->available = false;
        $tester = $this->tester();

        self::assertSame(EvaluateCommand::RATE_SOURCE_UNAVAILABLE, $tester->execute([]));
        self::assertSame(2, EvaluateCommand::RATE_SOURCE_UNAVAILABLE);
        self::assertStringStartsWith("inactive: rate source unavailable (dsstats_enable must be on).\n", $tester->getDisplay());
        self::assertSame([], $this->states->saved);
    }

    public function testExitsNonzeroWhenTheDatabaseIsUnavailable(): void
    {
        // Port 1 on loopback refuses at once: the database is unreachable, not slow.
        $database = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'port' => 1, 'user' => 'nobody', 'password' => 'not-a-real-secret', 'dbname' => 'cacti']);
        $this->settings = new DbalAlertingSettings($database);
        $tester = $this->tester();

        self::assertSame(1, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Alert evaluation failed', $display);
        self::assertStringNotContainsString('not-a-real-secret', $display);
        self::assertStringNotContainsString('127.0.0.1', $display, 'Driver messages can name the server; only the class is shown.');
        self::assertSame([], $this->lock->events);
        self::assertSame('error', $this->logger->records[0]['level'] ?? null);
        self::assertStringNotContainsString('not-a-real-secret', json_encode($this->logger->records, JSON_THROW_ON_ERROR));
    }

    public function testExitsOneWhenEvaluationFailsAndReleasesTheLock(): void
    {
        $this->rates->failure = new \RuntimeException('SELECT secret FROM somewhere');
        $tester = $this->tester();

        self::assertSame(1, $tester->execute([]));
        self::assertStringNotContainsString('SELECT', $tester->getDisplay());
        self::assertSame(['acquire evaluate', 'release evaluate'], $this->lock->events);
    }

    public function testExitsOneWhenDeliveryFails(): void
    {
        $this->drain->failure = new \RuntimeException('The alert notification worker failed.');
        $tester = $this->tester();

        self::assertSame(1, $tester->execute([]));
        self::assertCount(2, $this->states->saved, 'The evaluation before the failed delivery stays committed.');
    }

    public function testASecondConcurrentRunExitsZeroWithoutEvaluating(): void
    {
        $this->lock->elsewhere = [RunLock::EVALUATE];
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]));
        self::assertSame("skipped: another alert evaluation is running.\n", $tester->getDisplay());
        self::assertSame([], $this->rates->requests);
        self::assertSame([], $this->states->saved);
    }

    public function testUnusableRulesAreReportedButDoNotChangeTheExitStatus(): void
    {
        $this->rules->unusable[3] = 'alert_rule.comparator is not a known comparator.';
        $tester = $this->tester();

        // The run completed and moved past the rule (ADR 0011 decision 8); the warning is in the log.
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('1 skipped as unusable', $tester->getDisplay());
    }

    public function testABusyDelivererIsReported(): void
    {
        $this->lock->elsewhere = [RunLock::DELIVER];
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]));
        self::assertStringEndsWith("Notifications: another delivery is running.\n", $tester->getDisplay());
    }

    public function testOptionsReachTheRun(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--batch-size' => '1', '--budget' => '0', '--delivery-batch-size' => '3']));
        self::assertStringStartsWith('Alert rules: 1 evaluated,', $tester->getDisplay());
        self::assertSame([[3, 0.0]], $this->drain->calls);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidOptions(): iterable
    {
        yield 'zero batch' => ['--batch-size', '0'];
        yield 'negative batch' => ['--batch-size', '-5'];
        yield 'decimal batch' => ['--batch-size', '1.5'];
        yield 'exponent batch' => ['--batch-size', '1e3'];
        yield 'batch above the cap' => ['--batch-size', '10001'];
        yield 'padded batch' => ['--batch-size', ' 5'];
        yield 'negative budget' => ['--budget', '-1'];
        yield 'text budget' => ['--budget', 'soon'];
        yield 'budget above the cap' => ['--budget', '3601'];
        yield 'zero delivery batch' => ['--delivery-batch-size', '0'];
    }

    #[DataProvider('invalidOptions')]
    public function testRejectsAnInvalidOptionBeforeReadingAnything(string $option, string $value): void
    {
        $settings = new FixedAlertingSettings(new AlertingConfiguration(true));
        $this->settings = $settings;
        $tester = $this->tester();

        self::assertSame(1, $tester->execute([$option => $value]));
        self::assertStringContainsString($option, $tester->getDisplay());
        self::assertSame(0, $settings->reads);
        self::assertSame([], $this->lock->events);
    }

    public function testAFractionalBudgetIsAccepted(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--budget' => '2.5']));
        self::assertSame([[RunAlerting::DELIVERY_BATCH_SIZE, 2.5]], $this->drain->calls);
    }

    public function testTheCommandIsRegisteredInTheKernel(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $kernel->getContainer()->get('test.service_container')->set(AlertingSettings::class, new FixedAlertingSettings(new AlertingConfiguration(false)));
            $application = new Application($kernel);
            $application->setAutoExit(false);
            $tester = new ApplicationTester($application);
            self::assertSame(0, $tester->run(['command' => 'kadupul:alerting:evaluate']));
            self::assertSame("inactive: alerting is disabled (alerting_enable is off).\n", $tester->getDisplay());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testTheDeliveryWorkerCanReadTheUseCaseFromAProductionContainer(): void
    {
        // bin/legacy-alerting-deliver.php reads it with get(), which only reaches public services.
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            self::assertInstanceOf(DeliverNotifications::class, $kernel->getContainer()->get(DeliverNotifications::class));
        } finally {
            $kernel->shutdown();
        }
    }
}
