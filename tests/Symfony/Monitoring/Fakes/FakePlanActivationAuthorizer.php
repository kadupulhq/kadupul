<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring\Fakes;

use Kadupul\Monitoring\Application\Port\PlanActivationAuthorizer;
use Kadupul\Monitoring\Domain\CollectionPlan;
use Kadupul\Monitoring\Domain\PlanStatus;

/** Grants exactly the actor ids it was given; IdentityAccess will answer this for real. */
final class FakePlanActivationAuthorizer implements PlanActivationAuthorizer
{
    /** @var list<string> */
    public array $asked = [];

    /** @param list<string> $authorized */
    public function __construct(private readonly array $authorized) {}

    public function isAuthorized(string $actorId, CollectionPlan $plan, PlanStatus $target): bool
    {
        $this->asked[] = $actorId . ' ' . $target->value . ' ' . $plan->planId;

        return in_array($actorId, $this->authorized, true);
    }
}
