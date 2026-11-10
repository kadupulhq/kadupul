<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Domain\Evaluation\Transition;

/**
 * What a notification mail says. The value and threshold were stored when
 * the incident changed; the series is read when the mail is sent, so its
 * fields are null once the rule, data source or device is gone. Only these
 * fields reach the mail: no device credential, setting or query does.
 */
final readonly class NotificationContent
{
    /**
     * @param int $observedAt the observation time that opened or closed the incident
     */
    public function __construct(
        public int $incidentId,
        public int $ruleId,
        public Transition $kind,
        public float $value,
        public float $threshold,
        public int $observedAt,
        public ?int $localDataId = null,
        public ?string $rrdName = null,
        public ?string $deviceDescription = null,
    ) {}
}
