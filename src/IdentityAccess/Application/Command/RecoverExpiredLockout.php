<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Application\Port\AccountLockoutRecovery;
use Kadupul\IdentityAccess\Application\Port\AccountLockoutStatus;
use Kadupul\IdentityAccess\Application\Port\Clock;

/** Recovery does not authenticate: the caller must still recheck account policy. */
final readonly class RecoverExpiredLockout
{
    public function __construct(
        private AccountLockoutStatus $status,
        private AccountLockoutRecovery $recovery,
        private Clock $clock,
    ) {}

    public function __invoke(string $username, int|string $realm): void
    {
        if ($this->status->failureLimit() <= 0) {
            return;
        }
        $account = $this->recovery->find($username, $realm);
        if ($account === null) {
            return;
        }
        $minutes = min(1440, $this->recovery->unlockMinutes());
        $window = $minutes * 60;
        $elapsed = $this->clock->now()->getTimestamp() - $account['lastfail'];
        $this->recovery->recordCheck($username, $elapsed, $window);
        // Native policy uses strict expiry and zero/negative means manual unlock.
        if ($minutes > 0 && $elapsed > $window) {
            $this->recovery->clear($username, $realm, $account['lastfail'], $account['failed_attempts']);
        }
    }
}
