<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Domain\Evaluation\Transition;

/**
 * The alert_notification queue (ADR 0011 decision 7). The evaluator enqueues
 * inside the rule's transaction; DeliverNotifications claims, sends and
 * settles rows in its own process.
 *
 * A row is `queued` until its next attempt time, `sending` while a deliverer
 * holds it, then `sent`, `suppressed` when no administrator is to be mailed,
 * or, with no attempts left, `failed`. All three are final. A claim is a
 * lease: while a row is `sending` its next_attempt_at is the instant the
 * claim expires, and the claim's holder is whoever set that value. A claim
 * that expires (a deliverer that died) makes the row due again, so delivery
 * is at least once, never silently dropped.
 */
interface NotificationQueue
{
    /**
     * Queues one mail for an incident that opened or recovered, with the
     * value and threshold that decided it. Runs inside the rule's
     * TransactionRunner call, so a failure rolls the state and incident back.
     *
     * @throws \InvalidArgumentException for any other transition
     */
    public function enqueue(int $incidentId, Transition $kind, float $value, float $threshold, int $queuedAt): void;

    /**
     * Rows whose claim expired at or before $now with no attempt left become
     * `failed`, because their last attempt never reported back.
     *
     * @return int rows failed
     */
    public function failExpiredClaims(int $now, int $maxAttempts): int;

    /**
     * Up to $limit ids due at $now with fewer than $maxAttempts attempts,
     * earliest due first: queued rows whose next attempt time has come and
     * rows whose claim expired.
     *
     * @return list<int>
     */
    public function due(int $now, int $maxAttempts, int $limit): array;

    /**
     * Takes one row in a single conditional write, counting the attempt, so
     * two deliverers never both hold it. Null when it is no longer due: sent,
     * claimed by another deliverer, or out of attempts.
     */
    public function claim(int $id, int $now, int $maxAttempts, int $leaseUntil): ?ClaimedNotification;

    /** @return bool false when the claim was lost: it expired and another deliverer holds the row */
    public function markSent(ClaimedNotification $claim, int $sentAt): bool;

    /**
     * Ends the row as `suppressed` with its reason: the administrator
     * notification is turned off or has no administrator, so no retry could
     * send it.
     *
     * @return bool false when the claim was lost
     */
    public function markSuppressed(ClaimedNotification $claim, string $reason): bool;

    /**
     * Records a failed attempt and its error, which must hold no secret: the
     * row is queued again at $retryAt, or `failed` when that is null.
     *
     * @return bool false when the claim was lost
     */
    public function markAttemptFailed(ClaimedNotification $claim, string $error, ?int $retryAt): bool;
}
