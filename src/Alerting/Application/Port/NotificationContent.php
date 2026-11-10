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
 * the incident changed. The series and $ruleKind are the ones the incident
 * recorded when it opened, so a later change to the rule does not rename
 * them; the series fields are null once that data source is gone, and the
 * device once it has no device. Only these fields reach the mail: no device
 * credential, setting or query does. A baseline rule's stored threshold is
 * the band edge it crossed, not a configured threshold, and the mail says so.
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
        public ?string $ruleKind = null,
    ) {}
}
