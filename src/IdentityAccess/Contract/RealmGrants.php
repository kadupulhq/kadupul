<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Contract;

/** Display-only realm lookup; it never authorizes a route or a write. */
interface RealmGrants
{
    /**
     * @param list<int> $realmIds
     *
     * @return list<int> The subset of $realmIds granted directly or through an enabled group.
     */
    public function grantedRealms(Actor $actor, array $realmIds): array;
}
