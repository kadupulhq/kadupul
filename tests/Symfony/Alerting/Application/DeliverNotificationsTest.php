<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Application;

use Kadupul\Alerting\Application\Command\NotifyAdministrator;
use Kadupul\Alerting\Application\DeliverNotifications;
use Kadupul\Alerting\Application\DeliveryReport;
use Kadupul\Alerting\Application\MailDeliveryFailed;
use Kadupul\Alerting\Application\NotificationMail;
use Kadupul\Alerting\Application\Port\NotificationContent;
use Kadupul\Alerting\Domain\AdministratorNotificationPolicy;
use Kadupul\Alerting\Domain\AdministratorRecipient;
use Kadupul\Alerting\Domain\Evaluation\Transition;
use Kadupul\Tests\Alerting\Fakes\FixedAdministrator;
use Kadupul\Tests\Alerting\Fakes\InMemoryIncidentRepository;
use Kadupul\Tests\Alerting\Fakes\InMemoryNotificationQueue;
use Kadupul\Tests\Alerting\Fakes\MockClockAdapter;
use Kadupul\Tests\Alerting\Fakes\RecordingLogger;
use Kadupul\Tests\Alerting\Fakes\RecordingMailDelivery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\MockClock;

final class DeliverNotificationsTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    private MockClock $clock;
    private InMemoryIncidentRepository $incidents;
    private InMemoryNotificationQueue $queue;
    private RecordingMailDelivery $mail;
    private RecordingLogger $logger;
    private AdministratorNotificationPolicy $policy;
    private AdministratorRecipient $admin;
    private FixedAdministrator $administrator;

    protected function setUp(): void
    {
        $this->clock = new MockClock(new \DateTimeImmutable('@' . self::NOW));
        $this->incidents = new InMemoryIncidentRepository();
        $this->queue = new InMemoryNotificationQueue($this->incidents);
        $this->mail = new RecordingMailDelivery();
        $this->logger = new RecordingLogger();
        $this->policy = new AdministratorNotificationPolicy(1, true);
        $this->admin = new AdministratorRecipient('admin@example.test', 'Admin');
        $this->administrator = new FixedAdministrator($this->admin);
    }

    private function deliverer(): DeliverNotifications
    {
        $this->administrator->policy = $this->policy;

        return new DeliverNotifications($this->queue, new NotifyAdministrator($this->administrator, $this->administrator, $this->mail), new MockClockAdapter($this->clock), $this->logger);
    }

    /**
     * An incident that opened at NOW - 60 and its queued notification: the
     * first is rule 5's on data source 101, later ones are rules 6, 7 and on.
     */
    private function queueOpened(float $value = 95.5): int
    {
        $rule = 5 + count($this->queue->rows);
        $incident = $this->incidents->open($rule, self::NOW - 60);
        $this->queue->series[$rule] = [96 + $rule, 'traffic_in', 'core-router'];
        $this->queue->enqueue($incident, Transition::Opened, $value, 90.0, self::NOW - 60);

        return array_key_last($this->queue->rows);
    }

    private function at(int $time): void
    {
        $this->clock->sleep($time - $this->clock->now()->getTimestamp());
    }

    public function testAQueuedNotificationIsMarkedSentOnlyAfterTheTransportSucceeds(): void
    {
        $id = $this->queueOpened();
        $statusDuringSend = null;
        $this->mail->during = function () use ($id, &$statusDuringSend): void {
            $statusDuringSend = $this->queue->rows[$id]['status'];
        };

        $report = $this->deliverer()(10, 30.0);

        self::assertSame('sending', $statusDuringSend, 'Not sent while the transport has not answered.');
        self::assertEquals(new DeliveryReport(sent: 1), $report);
        self::assertSame(['sent', 1, self::NOW], [$this->queue->rows[$id]['status'], $this->queue->rows[$id]['attempts'], $this->queue->rows[$id]['sent']]);
        self::assertCount(1, $this->mail->sent);
        self::assertSame($this->admin, $this->mail->sent[0]['recipient'], 'Phase 1 sends to the administrator NotifyAdministrator selects.');
        self::assertSame('Alert rule 5 opened an incident', $this->mail->sent[0]['subject']);

        self::assertEquals(new DeliveryReport(), $this->deliverer()(10, 30.0), 'A sent row is not sent again.');
        self::assertCount(1, $this->mail->sent);
    }

    public function testTransportFailureKeepsNotificationQueuedWithAttemptCount(): void
    {
        $id = $this->queueOpened();
        $this->mail->failures = array_fill(0, 6, new MailDeliveryFailed());

        $report = $this->deliverer()(10, 30.0);

        self::assertEquals(new DeliveryReport(retrying: 1), $report);
        $row = $this->queue->rows[$id];
        self::assertSame(['queued', 1, self::NOW + 60, null], [$row['status'], $row['attempts'], $row['next'], $row['sent']]);
        self::assertSame('MailDeliveryFailed: Mail could not be confirmed. Check the installation mail settings and SMTP server logs before retrying.', $row['error']);
        self::assertSame([], $this->mail->sent);

        // Not due before its next attempt time.
        $this->at(self::NOW + 59);
        self::assertEquals(new DeliveryReport(), $this->deliverer()(10, 30.0));
        self::assertCount(5, $this->mail->failures, 'No attempt before the retry time.');

        // Attempts 2 to 4 back off by 300, 900 and 1800 seconds.
        $next = self::NOW + 60;
        foreach ([2 => 300, 3 => 900, 4 => 1800] as $attempt => $delay) {
            $this->at($next);
            self::assertEquals(new DeliveryReport(retrying: 1), $this->deliverer()(10, 30.0));
            $next += $delay;
            self::assertSame(['queued', $attempt, $next], [$this->queue->rows[$id]['status'], $this->queue->rows[$id]['attempts'], $this->queue->rows[$id]['next']]);
        }
        self::assertSame([], $this->logger->records, 'A row with attempts left is not reported as failed.');

        // The fifth attempt is the last.
        $this->at($next);
        self::assertEquals(new DeliveryReport(failed: 1), $this->deliverer()(10, 30.0));
        self::assertSame(['failed', 5, null], [$this->queue->rows[$id]['status'], $this->queue->rows[$id]['attempts'], $this->queue->rows[$id]['sent']]);
        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::ERROR, $this->logger->records[0]['level']);
        self::assertSame(['notification' => $id, 'incident' => 1, 'attempts' => 5, 'error' => $this->queue->rows[$id]['error']], $this->logger->records[0]['context']);

        // It is never retried.
        $this->at($next + 86_400);
        self::assertEquals(new DeliveryReport(), $this->deliverer()(10, 30.0));
        self::assertCount(1, $this->mail->failures, 'Five attempts were made, no more.');
        self::assertSame('failed', $this->queue->rows[$id]['status']);
    }

    public function testAnErrorThatMayHoldSecretsIsRecordedByItsClassOnly(): void
    {
        $id = $this->queueOpened();
        $this->mail->failures = [new \RuntimeException("SQLSTATE[HY000] [1045] Access denied for user 'cactiuser' (using password: hunter2) in SELECT * FROM settings")];

        $this->deliverer()(10, 30.0);

        self::assertSame('RuntimeException', $this->queue->rows[$id]['error']);
    }

    public function testASuppressedAdministratorNotificationIsAFailedAttemptNotASend(): void
    {
        $id = $this->queueOpened();
        $this->policy = new AdministratorNotificationPolicy(1, false);

        self::assertEquals(new DeliveryReport(retrying: 1), $this->deliverer()(10, 30.0));

        self::assertSame([], $this->mail->sent);
        self::assertSame(['queued', 1, 'administrator notification: Disabled'], [$this->queue->rows[$id]['status'], $this->queue->rows[$id]['attempts'], $this->queue->rows[$id]['error']]);
    }

    public function testNotificationBodyContainsNoCredentialsOrRawSql(): void
    {
        $opened = new NotificationContent(12, 5, Transition::Opened, 95.5, 90.0, self::NOW, 101, 'traffic_in', 'core <b>&</b> "edge" <SUBJECT>');

        self::assertSame('Alert rule 5 opened an incident', NotificationMail::subject($opened));
        self::assertSame(
            'Alert rule 5 opened an incident.<br>' . "\n"
            . 'Rule: alert rule 5<br>' . "\n"
            . 'Series: device core &lt;b&gt;&amp;&lt;/b&gt; &quot;edge&quot; &lt;SUBJECT&gt;, data source 101, traffic_in<br>' . "\n"
            . 'Transition: opened<br>' . "\n"
            . 'Value: 95.5<br>' . "\n"
            . 'Trigger threshold: 90<br>' . "\n"
            . 'Time: 2027-01-15 08:00:00 UTC<br>' . "\n"
            . 'Incident: /alerting/incidents/12',
            NotificationMail::html($opened),
            'Device text is escaped for the HTML mail path, so it cannot add markup or a legacy <SUBJECT> placeholder.',
        );

        $recovered = new NotificationContent(12, 5, Transition::Recovered, 0.000125, -3.5, self::NOW + 300);
        self::assertSame('Alert rule 5 recovered', NotificationMail::subject($recovered));
        $html = NotificationMail::html($recovered);
        self::assertStringContainsString('Series: no longer defined<br>', $html, 'A deleted rule or data source names no series.');
        self::assertStringContainsString('Value: 0.000125<br>', $html);
        self::assertStringContainsString('Clear threshold: -3.5<br>', $html);
        self::assertStringContainsString('Transition: recovered<br>', $html);
        self::assertStringContainsString('Time: 2027-01-15 08:05:00 UTC<br>', $html);

        $deviceless = new NotificationContent(12, 5, Transition::Opened, 1.0, 0.5, self::NOW, 101, "in\xff", null);
        self::assertStringContainsString("Series: data source 101, in\u{FFFD}<br>", NotificationMail::html($deviceless), 'A byte name that is not UTF-8 is replaced, not passed through.');

        // What the deliverer sends is exactly this mail, built from the stored fields only.
        $this->queueOpened();
        $this->deliverer()(10, 30.0);
        $body = $this->mail->sent[0]['html'];
        foreach (['SELECT', 'INSERT', 'alert_notification', 'password', 'community', 'mysql:', 'smtp'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $body);
        }
        self::assertStringContainsString('Series: device core-router, data source 101, traffic_in<br>', $body);
    }

    public function testAnUnreadableNotificationIsFailedAndNeverSent(): void
    {
        $id = $this->queueOpened();
        $this->queue->unreadable[$id] = true;

        self::assertEquals(new DeliveryReport(failed: 1), $this->deliverer()(10, 30.0));

        self::assertSame([], $this->mail->sent);
        self::assertSame(['failed', 1, 'the notification or its incident cannot be read'], [$this->queue->rows[$id]['status'], $this->queue->rows[$id]['attempts'], $this->queue->rows[$id]['error']]);
        self::assertSame(LogLevel::ERROR, $this->logger->records[0]['level']);
    }

    public function testTwoDeliverersNeverSendTheSameRow(): void
    {
        $this->queueOpened();
        $this->queueOpened(97.0);
        $inner = null;
        // A second deliverer runs while the first is inside its first send.
        $this->mail->during = function () use (&$inner): void {
            if ($inner === null) {
                $inner = false;
                $inner = $this->deliverer()(10, 30.0);
            }
        };

        $outer = $this->deliverer()(10, 30.0);

        self::assertSame([1, 1], [$outer->sent, $inner->sent], 'The second deliverer took only the row the first had not claimed.');
        self::assertSame([97.0, 95.5], array_map(static fn(array $mail): float => (float) substr($mail['html'], strpos($mail['html'], 'Value: ') + 7), $this->mail->sent), 'Each row was sent once.');
        self::assertSame(['sent' => 2], $this->queue->statuses());
    }

    public function testAnExpiredClaimIsDeliveredAgainAndOneWithNoAttemptsLeftFails(): void
    {
        $crashed = $this->queueOpened();
        $exhausted = $this->queueOpened(97.0);
        // A deliverer claimed both and died; the second was on its last attempt.
        $this->queue->rows[$exhausted]['attempts'] = DeliverNotifications::MAX_ATTEMPTS - 1;
        self::assertNotNull($this->queue->claim($crashed, self::NOW, DeliverNotifications::MAX_ATTEMPTS, self::NOW + DeliverNotifications::LEASE_SECONDS));
        self::assertNotNull($this->queue->claim($exhausted, self::NOW, DeliverNotifications::MAX_ATTEMPTS, self::NOW + DeliverNotifications::LEASE_SECONDS));

        $this->at(self::NOW + DeliverNotifications::LEASE_SECONDS - 1);
        self::assertEquals(new DeliveryReport(), $this->deliverer()(10, 30.0), 'A live claim is left alone.');

        $this->at(self::NOW + DeliverNotifications::LEASE_SECONDS);
        $report = $this->deliverer()(10, 30.0);

        self::assertEquals(new DeliveryReport(sent: 1, failed: 1), $report);
        self::assertSame(['sent', 2], [$this->queue->rows[$crashed]['status'], $this->queue->rows[$crashed]['attempts']]);
        self::assertSame(['failed', DeliverNotifications::MAX_ATTEMPTS], [$this->queue->rows[$exhausted]['status'], $this->queue->rows[$exhausted]['attempts']]);
        self::assertSame(LogLevel::ERROR, $this->logger->records[0]['level']);
        self::assertSame(['count' => 1], $this->logger->records[0]['context']);
    }

    public function testALostClaimIsReportedAndLeftToItsNewHolder(): void
    {
        $id = $this->queueOpened();
        // The send outlasts the lease and another deliverer takes the row.
        $this->mail->during = function () use ($id): void {
            $this->at(self::NOW + DeliverNotifications::LEASE_SECONDS);
            $this->queue->claim($id, self::NOW + DeliverNotifications::LEASE_SECONDS, DeliverNotifications::MAX_ATTEMPTS, self::NOW + 2 * DeliverNotifications::LEASE_SECONDS);
        };

        self::assertEquals(new DeliveryReport(lost: 1), $this->deliverer()(10, 30.0));

        self::assertSame(['sending', 2, self::NOW + 2 * DeliverNotifications::LEASE_SECONDS], [$this->queue->rows[$id]['status'], $this->queue->rows[$id]['attempts'], $this->queue->rows[$id]['next']]);
        self::assertSame(LogLevel::WARNING, $this->logger->records[0]['level']);
        self::assertSame(['notification' => $id], $this->logger->records[0]['context']);
    }

    public function testBatchesAreBoundedAndTheBudgetIsCheckedBetweenThem(): void
    {
        foreach (range(1, 5) as $n) {
            $this->queueOpened(90.0 + $n);
        }
        // Each send costs 0.3 s, a batch of two 0.6 s, against a 1 s budget.
        $this->mail->during = function (): void {
            $this->clock->sleep(0.3);
        };

        self::assertSame(4, $this->deliverer()(2, 1.0)->sent, 'Two batches of two fit: the budget is checked between batches.');
        self::assertSame(1, $this->deliverer()(2, 0.0)->sent, 'A zero budget still runs one batch.');
        self::assertSame(['sent' => 5], $this->queue->statuses());
    }

    public function testInvalidLimitsAreRefusedBeforeAnyClaim(): void
    {
        $this->queueOpened();
        foreach ([[0, 1.0], [1, -1.0], [1, NAN], [1, INF]] as [$batch, $budget]) {
            try {
                $this->deliverer()($batch, $budget);
                self::fail('Accepted batch ' . $batch . ' and budget ' . $budget);
            } catch (\InvalidArgumentException) {
                self::assertSame(['queued' => 1], $this->queue->statuses());
            }
        }
    }
}
