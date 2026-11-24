<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Query;

use Kadupul\IdentityAccess\Application\Port\AccountLockoutStatus;

/** The caller remains responsible for account enablement and authentication. */
final readonly class IsAccountLocked
{
    public function __construct(private AccountLockoutStatus $status) {}

    public function __invoke(string $username, int|string $realm): bool
    {
        // Disabled lockout policy must not query lockout columns: pre-upgrade
        // installations can reach this gate before those columns exist.
        return $this->status->failureLimit() > 0 && $this->status->hasLockedEnabledAccount($username, $realm);
    }
}
