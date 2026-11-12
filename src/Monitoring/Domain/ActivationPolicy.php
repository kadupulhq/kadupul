<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * Whether plans may be approved and activated at all. The default denies, so
 * installing the module changes nothing until an operator turns this on, and
 * even then each step needs an authorized actor.
 */
final readonly class ActivationPolicy
{
    /** Starts denying activation unless $allowApproved is true. */
    public function __construct(private bool $allowApproved = false) {}

    /** Creates a policy that lets plans be approved and activated. */
    public static function allowApproved(): self
    {
        return new self(true);
    }

    /**
     * Refuses a plan status change while activation is disabled.
     *
     * @throws PlanActivationDenied when the policy does not allow plan activation
     */
    public function assertPermits(CollectionPlan $plan, PlanStatus $target): void
    {
        if (!$this->allowApproved) {
            throw new PlanActivationDenied(sprintf('The activation policy denies moving plan %s to %s; plan activation is disabled.', $plan->planId, $target->value));
        }
    }
}
