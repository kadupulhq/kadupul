<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Rule;

/**
 * A stored rule: its id (the evaluation cursor orders by it), the series it
 * watches and its condition. Baseline conditions arrive with BaselineRule.
 */
final readonly class AlertRule
{
    public function __construct(
        public int $id,
        public SeriesKey $series,
        public ThresholdRule $condition,
    ) {
        if ($id < 1) {
            throw new InvalidRule('A rule id must be at least 1.');
        }
    }
}
