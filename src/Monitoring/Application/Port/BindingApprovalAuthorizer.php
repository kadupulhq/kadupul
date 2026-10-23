<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application\Port;

use Kadupul\Monitoring\Domain\BindingKey;

/**
 * Decides whether an actor may approve a binding. The intended adapter asks
 * IdentityAccess through its Contract; until one is wired, ApproveBinding
 * denies every approval.
 */
interface BindingApprovalAuthorizer
{
    public function isAuthorized(string $actorId, BindingKey $key): bool;
}
