<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\ReadModel;

use Kadupul\Alerting\Domain\Rule\BaselineRule;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;

/**
 * A rule as the rule pages show it. The series is named as the notification
 * mail names it: the device description, the data source id and the RRD
 * data source name, never the data source title, which can substitute device
 * fields. $condition is null for a stored row the domain refuses; the page
 * then shows $unusable and the row can still be corrected or deleted.
 * $revision identifies the stored row, so a save or delete of a rule that
 * changed since the form was read is refused.
 */
final readonly class RuleView
{
    public function __construct(
        public int $id,
        public int $localDataId,
        public string $rrdName,
        public bool $seriesExists,
        public ?string $device,
        public ThresholdRule|BaselineRule|null $condition,
        public ?string $unusable,
        public string $revision,
        public ?int $activeIncidentId,
    ) {}

    public function kind(): ?string
    {
        return match (true) {
            $this->condition instanceof ThresholdRule => 'threshold',
            $this->condition instanceof BaselineRule => 'baseline',
            default => null,
        };
    }
}
