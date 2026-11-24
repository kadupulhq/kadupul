<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\DeliveryReport;
use Kadupul\Alerting\Application\Port\NotificationDrain;

final class RecordingNotificationDrain implements NotificationDrain
{
    /** @var list<array{int, float}> batch size and budget of each call */
    public array $calls = [];

    /** @param ?\Closure(): void $during runs inside drain(), to observe what holds then */
    public function __construct(public DeliveryReport $report = new DeliveryReport(), public ?\Throwable $failure = null, public ?\Closure $during = null) {}

    public function drain(int $batchSize, float $budgetSeconds): DeliveryReport
    {
        $this->calls[] = [$batchSize, $budgetSeconds];
        if ($this->during !== null) {
            ($this->during)();
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->report;
    }
}
