<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Legacy;

use Kadupul\Alerting\Application\DeliverNotifications;
use Kadupul\Alerting\Application\DeliveryReport;
use Kadupul\Alerting\Application\Port\NotificationDrain;
use Kadupul\Platform\Contract\LegacyWorkerRunner;

/**
 * Runs DeliverNotifications in bin/legacy-alerting-deliver.php. The
 * administrator mail adapters call read_config_option(), the legacy user
 * lookup and send_mail(), which only a process with the procedural runtime
 * has; admin_email() reaches the same use case the same way.
 */
final readonly class LegacyNotificationDrain implements NotificationDrain
{
    public const string MARKER = 'KADUPUL_ALERTING_DELIVERY_RESULT';
    private const array COUNTS = ['sent', 'retrying', 'failed', 'lost', 'suppressed'];

    public function __construct(private LegacyWorkerRunner $worker) {}

    public function drain(int $batchSize, float $budgetSeconds): DeliveryReport
    {
        // Past one lease after its budget, every claim the worker still holds
        // has expired and may already be someone else's, so stopping it loses
        // no delivery that is not retried anyway.
        $run = $this->worker->run(
            'legacy-alerting-deliver.php',
            self::MARKER,
            ['batch_size' => $batchSize, 'budget_seconds' => $budgetSeconds],
            $budgetSeconds + DeliverNotifications::LEASE_SECONDS,
        );
        $counts = [];
        foreach (self::COUNTS as $name) {
            $value = $run['result'][$name] ?? null;
            if (!is_int($value) || $value < 0) {
                $counts = null;
                break;
            }
            $counts[$name] = $value;
        }
        if (!$run['ok'] || $counts === null) {
            throw new \RuntimeException('The alert notification worker did not report a delivery.');
        }

        return new DeliveryReport($counts['sent'], $counts['retrying'], $counts['failed'], $counts['lost'], $counts['suppressed']);
    }
}
