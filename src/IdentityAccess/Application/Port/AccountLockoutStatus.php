<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

/** Current installation policy and persisted account state; neither is cached here. */
interface AccountLockoutStatus
{
    public function failureLimit(): int;

    public function hasLockedEnabledAccount(string $username, int|string $realm): bool;
}
