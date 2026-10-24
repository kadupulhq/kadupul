<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application;

use Kadupul\Monitoring\Application\Port\CollectionPlanStore;
use Kadupul\Monitoring\Application\Port\PackRepository;
use Kadupul\Monitoring\Application\Port\PlanActivationAuthorizer;
use Kadupul\Monitoring\Domain\ActivationPolicy;
use Kadupul\Monitoring\Domain\CollectionPlan;
use Kadupul\Monitoring\Domain\PackState;
use Kadupul\Monitoring\Domain\PlanActivationDenied;
use Kadupul\Monitoring\Domain\PlanStatus;
use Kadupul\Monitoring\Domain\PlanTransitionRejected;

/**
 * The only way a plan becomes approved or active: proposed to approved, then
 * approved to active, each by an authorized actor and only when the policy
 * permits activation. Authorization is checked before anything is read from
 * or written to the store. Recording an audit event for each step is not
 * implemented yet.
 */
final class ActivateCollectionPlan
{
    public function __construct(
        private readonly CollectionPlanStore $plans,
        private readonly PackRepository $packs,
        private readonly ActivationPolicy $policy,
        private readonly ?PlanActivationAuthorizer $authorizer = null,
    ) {}

    public function approve(CollectionPlan $proposed, string $actorId): CollectionPlan
    {
        $this->authorize($proposed, $actorId, PlanStatus::Approved);
        $approved = $proposed->withStatus(PlanStatus::Approved);
        $this->plans->save($approved);

        return $approved;
    }

    public function activate(string $planId, string $actorId): CollectionPlan
    {
        $plan = $this->plans->find($planId)
            ?? throw new PlanTransitionRejected(sprintf('Plan %s is not stored; only approved plans can be activated.', $planId));
        $this->authorize($plan, $actorId, PlanStatus::Active);
        $active = $plan->withStatus(PlanStatus::Active);
        foreach ($this->plans->forDevice($plan->deviceId) as $other) {
            if ($other->planId !== $plan->planId && $other->packName === $plan->packName && $other->status === PlanStatus::Active) {
                $this->plans->save($other->withStatus(PlanStatus::Superseded));
            }
        }
        $this->plans->save($active);
        $pack = $this->packs->find($plan->packName);
        if ($pack !== null && $pack->state === PackState::Installed) {
            $this->packs->save($pack->withState(PackState::Active));
        }

        return $active;
    }

    private function authorize(CollectionPlan $plan, string $actorId, PlanStatus $target): void
    {
        $this->policy->assertPermits($plan, $target);
        if ($this->authorizer === null) {
            throw new PlanActivationDenied(sprintf('No plan activation authorizer is configured, so plan %s cannot be moved to %s.', $plan->planId, $target->value));
        }
        if (trim($actorId) === '') {
            throw new PlanActivationDenied('An actor id is required to approve or activate a plan.');
        }
        if (!$this->authorizer->isAuthorized($actorId, $plan, $target)) {
            throw new PlanActivationDenied(sprintf('%s is not authorized to move plan %s to %s.', $actorId, $plan->planId, $target->value));
        }
    }
}
