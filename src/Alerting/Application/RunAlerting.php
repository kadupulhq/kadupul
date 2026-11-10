<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application;

use Kadupul\Alerting\Application\Port\AlertingLog;
use Kadupul\Alerting\Application\Port\AlertingSettings;
use Kadupul\Alerting\Application\Port\Clock;
use Kadupul\Alerting\Application\Port\NotificationDrain;
use Kadupul\Alerting\Application\Port\RunLock;

/**
 * One alerting run as the poller starts it (ADR 0011 decisions 2, 7, 8 and 10):
 * nothing while alerting_enable is off; otherwise evaluate under the
 * evaluation lock, release it, then drain the notification queue under the
 * delivery lock with what is left of the time budget.
 *
 * The evaluation lock is released before delivery, so a slow mail server
 * never holds back the next cycle's evaluation; the delivery lock keeps a
 * second drain from piling up behind a slow one. Queued notifications are
 * drained even when the rate source is inactive: they report changes that
 * were already decided. A failed evaluation propagates without delivering.
 */
final readonly class RunAlerting
{
    /**
     * Notifications claimed per delivery batch. Each send can wait on the
     * mail server, so the first batch, which always runs, stays small.
     */
    public const int DELIVERY_BATCH_SIZE = 20;

    public function __construct(
        private AlertingSettings $settings,
        private RunLock $lock,
        private EvaluateRules $evaluate,
        private NotificationDrain $drain,
        private Clock $clock,
        private AlertingLog $logger,
    ) {}

    /**
     * @param ?int   $batchSize     rules per batch, or null for the stored setting
     * @param ?float $budgetSeconds the whole run's budget, or null for the stored setting
     */
    public function __invoke(?int $batchSize = null, ?float $budgetSeconds = null, int $deliveryBatchSize = self::DELIVERY_BATCH_SIZE): AlertingRun
    {
        $configuration = $this->settings->current();
        if (!$configuration->enabled) {
            return new AlertingRun(AlertingRunOutcome::Disabled);
        }
        $batchSize ??= $configuration->batchSize;
        $budgetSeconds ??= $configuration->budgetSeconds;
        $started = $this->seconds();

        if (!$this->lock->acquire(RunLock::EVALUATE)) {
            return new AlertingRun(AlertingRunOutcome::Busy);
        }
        try {
            $evaluation = ($this->evaluate)($batchSize, $budgetSeconds);
        } finally {
            $this->release(RunLock::EVALUATE);
        }

        if (!$this->lock->acquire(RunLock::DELIVER)) {
            return new AlertingRun(AlertingRunOutcome::Evaluated, $evaluation);
        }
        try {
            $delivery = $this->drain->drain($deliveryBatchSize, max(0.0, $budgetSeconds - ($this->seconds() - $started)));
        } finally {
            $this->release(RunLock::DELIVER);
        }

        return new AlertingRun(AlertingRunOutcome::Evaluated, $evaluation, $delivery);
    }

    private function release(string $purpose): void
    {
        if (!$this->lock->release($purpose)) {
            $this->logger->warning('The alerting {lock} lock was not held at release; it ends with the database session.', ['lock' => $purpose]);
        }
    }

    private function seconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
