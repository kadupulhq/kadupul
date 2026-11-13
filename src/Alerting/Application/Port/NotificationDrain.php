<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Application\DeliveryReport;

/**
 * Runs DeliverNotifications where the administrator mail port works. The
 * legacy mail adapters need the procedural runtime, which a console process
 * does not load, so the adapter runs it in a worker.
 */
interface NotificationDrain
{
    /** @throws \RuntimeException when the delivery outcome is unknown */
    public function drain(int $batchSize, float $budgetSeconds): DeliveryReport;
}
