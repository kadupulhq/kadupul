<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Telemetry\Contract\SampleOutcome;

/** What the formatter decided for one file: each entry is either refused here or placed in exactly one update. */
final readonly class UpdatePlan
{
    /**
     * @param array<int, SampleOutcome> $rejections keyed by sample position
     * @param list<PlannedUpdate> $updates in the order they must run
     */
    public function __construct(
        public array $rejections,
        public array $updates,
    ) {}
}
