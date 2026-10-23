<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application\Port;

use Kadupul\Monitoring\Domain\CollectionPlan;
use Kadupul\Monitoring\Domain\PlanStatus;

/**
 * Decides whether an actor may move a plan to approved or active. The
 * intended adapter asks IdentityAccess through its Contract; it is not
 * written yet, and ActivateCollectionPlan denies everything without one.
 */
interface PlanActivationAuthorizer
{
    public function isAuthorized(string $actorId, CollectionPlan $plan, PlanStatus $target): bool;
}
