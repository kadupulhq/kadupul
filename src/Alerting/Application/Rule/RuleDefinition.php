<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\Rule;

use Kadupul\Alerting\Domain\Rule\BaselineRule;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;

/** What the rule form saves: the series and a condition the domain has accepted. */
final readonly class RuleDefinition
{
    public const string THRESHOLD = 'threshold';
    public const string BASELINE = 'baseline';

    public function __construct(
        public SeriesKey $series,
        public ThresholdRule|BaselineRule $condition,
    ) {}

    /** The alert_rule.kind value. */
    public function kind(): string
    {
        return $this->condition instanceof ThresholdRule ? self::THRESHOLD : self::BASELINE;
    }
}
