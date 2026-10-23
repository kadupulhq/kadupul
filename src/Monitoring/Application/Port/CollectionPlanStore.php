<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application\Port;

use Kadupul\Monitoring\Domain\CollectionPlan;

/** Approved, active and superseded plans. Proposals are not stored until approved. */
interface CollectionPlanStore
{
    public function find(string $planId): ?CollectionPlan;

    /** @return list<CollectionPlan> ordered by generation time, then plan id */
    public function forDevice(int $deviceId): array;

    public function save(CollectionPlan $plan): void;
}
