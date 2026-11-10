<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application;

use Kadupul\Alerting\Application\Command\NotifyAdministrator;
use Kadupul\Alerting\Application\Port\AlertingLog;
use Kadupul\Alerting\Application\Port\ClaimedNotification;
use Kadupul\Alerting\Application\Port\Clock;
use Kadupul\Alerting\Application\Port\NotificationQueue;
use Kadupul\Alerting\Domain\AdministratorNotificationStatus;

/**
 * Drains the alert_notification queue (ADR 0011 decision 7), separately from
 * evaluation, which only queues. Rows are taken in batches, earliest due
 * first, and the time budget is checked between batches, so the first batch
 * always runs. Each row is claimed before it is sent, so two deliverers
 * never hold it at once, and sent through NotifyAdministrator: Phase 1 mails
 * the administrator that use case selects.
 *
 * A row is marked sent only after the mail port returns. A failed attempt,
 * including an administrator notification that is not configured, disabled
 * or has no address, queues the row again after a growing delay and records
 * the error; the fifth failed attempt marks it failed and logs it, and it is
 * not retried. A claim held longer than LEASE_SECONDS, such as one whose
 * deliverer died, makes the row due again: delivery is at least once.
 */
final readonly class DeliverNotifications
{
    public const int MAX_ATTEMPTS = 5;
    /** Seconds before attempt 2, 3, 4 and 5. */
    public const array RETRY_DELAYS = [60, 300, 900, 1800];
    /**
     * How long a claim holds a row. Longer than one SMTP send at the largest
     * timeout the mail settings accept (300 s per read), so a slow send is
     * not taken over while it is still running.
     */
    public const int LEASE_SECONDS = 900;
    public const string UNREADABLE = 'the notification or its incident cannot be read';
    private const int ERROR_LENGTH = 255;

    public function __construct(
        private NotificationQueue $queue,
        private NotifyAdministrator $notify,
        private Clock $clock,
        private AlertingLog $logger,
    ) {}

    public function __invoke(int $batchSize, float $budgetSeconds): DeliveryReport
    {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('The batch size must be at least 1.');
        }
        if (!is_finite($budgetSeconds) || $budgetSeconds < 0.0) {
            throw new \InvalidArgumentException('The time budget must be a finite number of seconds, zero or more.');
        }

        $started = $this->seconds();
        $tally = ['sent' => 0, 'retrying' => 0, 'failed' => 0, 'lost' => 0];
        $abandoned = $this->queue->failExpiredClaims($this->now(), self::MAX_ATTEMPTS);
        if ($abandoned > 0) {
            $tally['failed'] += $abandoned;
            $this->logger->error('{count} alert notifications failed: their last attempt did not report back before its claim expired.', ['count' => $abandoned]);
        }

        // A row is claimed at most once per call; a row due again at once (a lost claim) waits for the next call.
        $seen = [];
        do {
            $ids = array_values(array_diff($this->queue->due($this->now(), self::MAX_ATTEMPTS, $batchSize), $seen));
            foreach ($ids as $id) {
                $seen[] = $id;
                $now = $this->now();
                $claim = $this->queue->claim($id, $now, self::MAX_ATTEMPTS, $now + self::LEASE_SECONDS);
                if ($claim !== null) {
                    ++$tally[$this->deliver($claim)];
                }
            }
        } while ($ids !== [] && $this->seconds() - $started < $budgetSeconds);

        return new DeliveryReport($tally['sent'], $tally['retrying'], $tally['failed'], $tally['lost']);
    }

    /** @return 'sent'|'retrying'|'failed'|'lost' */
    private function deliver(ClaimedNotification $claim): string
    {
        if ($claim->content === null) {
            return $this->settle($claim, $this->queue->markAttemptFailed($claim, self::UNREADABLE, null), 'failed');
        }
        try {
            $status = ($this->notify)(NotificationMail::subject($claim->content), NotificationMail::html($claim->content));
            $error = $status === AdministratorNotificationStatus::Sent ? null : 'administrator notification: ' . $status->name;
        } catch (\Throwable $e) {
            // Only the port's own failure has a message known to hold no credential or query.
            $error = (new \ReflectionClass($e))->getShortName() . ($e instanceof MailDeliveryFailed ? ': ' . $e->getMessage() : '');
        }
        if ($error === null) {
            return $this->settle($claim, $this->queue->markSent($claim, $this->now()), 'sent');
        }
        $error = mb_substr($error, 0, self::ERROR_LENGTH);
        $delay = self::RETRY_DELAYS[$claim->attempts - 1] ?? null;
        if ($claim->attempts >= self::MAX_ATTEMPTS || $delay === null) {
            return $this->settle($claim, $this->queue->markAttemptFailed($claim, $error, null), 'failed', $error);
        }

        return $this->settle($claim, $this->queue->markAttemptFailed($claim, $error, $this->now() + $delay), 'retrying');
    }

    /**
     * @param 'sent'|'retrying'|'failed' $outcome
     *
     * @return 'sent'|'retrying'|'failed'|'lost'
     */
    private function settle(ClaimedNotification $claim, bool $held, string $outcome, string $error = self::UNREADABLE): string
    {
        if (!$held) {
            $this->logger->warning('Alert notification {notification} was attempted after its claim expired; another deliverer holds it, so it may be sent twice.', ['notification' => $claim->id]);

            return 'lost';
        }
        if ($outcome === 'failed') {
            $this->logger->error('Alert notification {notification} for incident {incident} failed after {attempts} attempts: {error}', [
                'notification' => $claim->id,
                'incident' => $claim->content?->incidentId,
                'attempts' => $claim->attempts,
                'error' => $error,
            ]);
        }

        return $outcome;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function seconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
