<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Rule;

/**
 * A stored rule: its id (the evaluation cursor orders by it), the series it
 * watches and its condition, a fixed threshold or an hour-of-week baseline.
 */
final readonly class AlertRule
{
    public function __construct(
        public int $id,
        public SeriesKey $series,
        public ThresholdRule|BaselineRule $condition,
    ) {
        if ($id < 1) {
            throw new InvalidRule('A rule id must be at least 1.');
        }
    }
}
