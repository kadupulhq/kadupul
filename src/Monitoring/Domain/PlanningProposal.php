<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/** A recommendation only: every plan in it is proposed, and nothing is stored or started. */
final readonly class PlanningProposal
{
    /**
     * @param list<PackMatch> $matches
     * @param list<CollectionPlan> $plans
     * @param list<Overlap> $overlaps
     */
    public function __construct(public int $deviceId, public array $matches, public array $plans, public array $overlaps) {}
}
