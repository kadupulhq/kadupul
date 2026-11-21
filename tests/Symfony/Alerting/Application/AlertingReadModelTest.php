<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Alerting\Application;

use Kadupul\Alerting\Application\AlertingConfiguration;
use Kadupul\Alerting\Application\DeliverNotifications;
use Kadupul\Alerting\Application\Port\AlertingSettings;
use Kadupul\Alerting\Application\Port\RateSource;
use Kadupul\Alerting\Application\Query\AlertingStatus;
use Kadupul\Alerting\Application\Query\ViewAlertingStatus;
use Kadupul\Alerting\Application\ReadModel\NotificationView;
use Kadupul\Alerting\Application\ReadModel\RuleView;
use Kadupul\Alerting\Application\Rule\RuleSubmission;
use Kadupul\Alerting\Application\Rule\RuleSubmissionRefused;
use Kadupul\Alerting\Domain\Rule\BaselineRule;
use Kadupul\Alerting\Domain\Rule\Comparator;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the alert pages read: the evaluation status line, a notification
 * row's shown status, a rule's kind, and the refusals of a submission that
 * reaches RuleSubmission without passing the form's own choice lists.
 */
final class AlertingReadModelTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    /** @return iterable<string, array{AlertingConfiguration|\Throwable, bool|\Throwable, AlertingStatus}> */
    public static function statuses(): iterable
    {
        yield 'enabled with rates' => [new AlertingConfiguration(true), true, AlertingStatus::Active];
        yield 'enabled without rates' => [new AlertingConfiguration(true), false, AlertingStatus::RateSourceUnavailable];
        yield 'disabled' => [new AlertingConfiguration(false), true, AlertingStatus::Disabled];
        yield 'settings unreadable' => [new \RuntimeException('settings table is gone'), true, AlertingStatus::Unknown];
        yield 'rate source unreadable' => [new AlertingConfiguration(true), new \RuntimeException('dsstats table is gone'), AlertingStatus::Unknown];
    }

    #[DataProvider('statuses')]
    public function testAFailedReadIsReportedAsUnknownNotAsActiveOrOff(AlertingConfiguration|\Throwable $settings, bool|\Throwable $available, AlertingStatus $expected): void
    {
        $settingsPort = $this->createStub(AlertingSettings::class);
        $settingsPort->method('current')->willReturnCallback(static fn(): AlertingConfiguration => $settings instanceof \Throwable ? throw $settings : $settings);
        $rates = $this->createMock(RateSource::class);
        $rates->expects($settings instanceof AlertingConfiguration && $settings->enabled ? self::once() : self::never())
            ->method('available')->willReturnCallback(static fn(): bool => $available instanceof \Throwable ? throw $available : $available);

        self::assertSame($expected, (new ViewAlertingStatus($settingsPort, $rates))());
    }

    /** @return iterable<string, array{string, int, int, string, ?int}> */
    public static function storedNotifications(): iterable
    {
        yield 'queued, never tried' => ['queued', 0, self::NOW + 60, NotificationView::QUEUED, self::NOW + 60];
        yield 'queued after a failure' => ['queued', 1, self::NOW + 60, NotificationView::RETRYING, self::NOW + 60];
        yield 'claim still held' => ['sending', 1, self::NOW + 1, NotificationView::SENDING, null];
        yield 'claim expired with attempts left' => ['sending', 1, self::NOW, NotificationView::RETRYING, self::NOW];
        yield 'claim expired on the last attempt' => ['sending', DeliverNotifications::MAX_ATTEMPTS, self::NOW, NotificationView::FAILED, null];
        yield 'sent' => ['sent', 1, self::NOW, NotificationView::SENT, null];
        yield 'suppressed' => ['suppressed', 1, self::NOW, NotificationView::SUPPRESSED, null];
        yield 'failed' => ['failed', 5, self::NOW, NotificationView::FAILED, null];
        // A status no writer stores is shown as such, never as one of the known states.
        yield 'a status no writer stores' => ['delivered', 1, self::NOW + 60, NotificationView::UNKNOWN, null];
        yield 'an empty status' => ['', 0, self::NOW + 60, NotificationView::UNKNOWN, null];
    }

    #[DataProvider('storedNotifications')]
    public function testAStoredNotificationIsShownWithTheStatusItCanStillReach(string $stored, int $attempts, int $nextAttemptAt, string $shown, ?int $next): void
    {
        $view = NotificationView::fromStored(9, 'opened', $stored, $attempts, self::NOW - 600, $nextAttemptAt, null, '', self::NOW);

        self::assertSame([$shown, $next, $attempts], [$view->status, $view->nextAttemptAt, $view->attempts]);
    }

    public function testARuleViewNamesItsKindOrNoneForAnUnusableRow(): void
    {
        $view = static fn(ThresholdRule|BaselineRule|null $condition): RuleView => new RuleView(7, 101, 'traffic_in', true, null, $condition, $condition === null ? 'alert_rule 7: comparator is not one the rule form writes.' : null, 'rev', null);

        self::assertSame('threshold', $view(new ThresholdRule(Comparator::GreaterThan, 90.0, 80.0, 1))->kind());
        self::assertSame('baseline', $view(new BaselineRule(3.0, 2.0, 0.5))->kind());
        self::assertNull($view(null)->kind());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function unchosenValues(): iterable
    {
        $threshold = ['kind' => 'threshold', 'local_data_id' => '101', 'rrd_name' => 'traffic_in', 'for_cycles' => '1', 'trigger_threshold' => '90', 'clear_threshold' => '80'];
        $baseline = ['kind' => 'baseline', 'local_data_id' => '101', 'rrd_name' => 'traffic_in', 'for_cycles' => '1', 'baseline_deviations' => '3', 'baseline_clear_deviations' => '2', 'baseline_floor' => '0.5'];
        yield 'comparator outside the list' => [$threshold + ['comparator' => 'eq'], RuleSubmission::INVALID_COMPARATOR];
        yield 'comparator missing' => [$threshold, RuleSubmission::INVALID_COMPARATOR];
        yield 'direction outside the list' => [$baseline + ['baseline_direction' => 'sideways'], RuleSubmission::INVALID_DIRECTION];
        yield 'direction in another case' => [$baseline + ['baseline_direction' => 'ABOVE'], RuleSubmission::INVALID_DIRECTION];
    }

    /** @param array<string, string> $fields */
    #[DataProvider('unchosenValues')]
    public function testAComparatorOrDirectionOutsideTheListIsRefused(array $fields, string $refusal): void
    {
        try {
            RuleSubmission::definition($fields);
            self::fail('A value outside the list was accepted.');
        } catch (RuleSubmissionRefused $refused) {
            self::assertSame($refusal, $refused->getMessage());
        }
    }
}
