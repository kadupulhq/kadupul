<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

final readonly class PlanChange
{
    /**
     * @param list<string> $addedMetrics "protocol:metric id" routes the proposed plan adds
     * @param list<string> $removedMetrics routes the current plan has and the proposed one drops
     */
    public function __construct(
        public CollectionPlan $current,
        public CollectionPlan $proposed,
        public array $addedMetrics,
        public array $removedMetrics,
    ) {}
}
