<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Persistence;

use Kadupul\Monitoring\Application\Port\CollectionPlanStore;
use Kadupul\Monitoring\Domain\CollectionPlan;

/** Holds plans for one process; lost when it ends. */
final class InMemoryCollectionPlanStore implements CollectionPlanStore
{
    /** @var array<string, CollectionPlan> */
    private array $plans = [];

    /** The plan with this id, or null. */
    public function find(string $planId): ?CollectionPlan
    {
        return $this->plans[$planId] ?? null;
    }

    /** The device's plans, oldest first. */
    public function forDevice(int $deviceId): array
    {
        $plans = array_values(array_filter($this->plans, static fn(CollectionPlan $p): bool => $p->deviceId === $deviceId));
        usort($plans, static fn(CollectionPlan $a, CollectionPlan $b): int => [$a->generatedAt, $a->planId] <=> [$b->generatedAt, $b->planId]);

        return $plans;
    }

    /** Stores the plan, replacing any with the same id. */
    public function save(CollectionPlan $plan): void
    {
        $this->plans[$plan->planId] = $plan;
    }
}
