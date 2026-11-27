<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

/**
 * Read-only realm membership lookup for authorization voters and guards.
 */
interface RealmAccessChecker
{
    public function hasRealm(int $actorId, int $realmId, bool $includeGroups = true): bool;
}
