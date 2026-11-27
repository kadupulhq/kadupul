<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Contract;

/**
 * Cross-context contract owned by IdentityAccess for managing dynamic realm
 * grants (for example external link and plugin realms) and invalidating cached
 * permission generations without letting callers write user_auth tables directly.
 */
interface RealmGrantManager
{
    /**
     * Acquires an exclusive row lock on the actor row when running inside a
     * MySQL transaction before authorization reads, avoiding lock upgrades.
     */
    public function lockActorForGrant(int $actorId): void;

    /**
     * Grants $realmId to $userId and increments the actor's reset_perms
     * generation in the same unit of work.
     */
    public function grantRealmAndInvalidateCache(int $userId, int $realmId): void;

    /**
     * Removes all direct and group realm grants for $realmId.
     */
    public function revokeRealm(int $realmId): void;
}
