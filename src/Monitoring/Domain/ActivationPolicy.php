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
    public function __construct(private bool $allowApproved = false) {}

    public static function allowApproved(): self
    {
        return new self(true);
    }

    public function assertPermits(CollectionPlan $plan, PlanStatus $target): void
    {
        if (!$this->allowApproved) {
            throw new PlanActivationDenied(sprintf('The activation policy denies moving plan %s to %s; plan activation is disabled.', $plan->planId, $target->value));
        }
    }
}
