<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain\Policy;

/**
 * Account lockout threshold and expiry rules matching secpass_lockfailed and
 * secpass_unlocktime semantics in lib/auth.php and RecoverExpiredLockout.
 */
final readonly class LockoutPolicy
{
    private const int MAX_UNLOCK_MINUTES = 1440;

    public int $unlockMinutes;

    public function __construct(
        public int $failureLimit,
        int $unlockMinutes,
    ) {
        $this->unlockMinutes = min(self::MAX_UNLOCK_MINUTES, $unlockMinutes);
    }

    public function isEnabled(): bool
    {
        return $this->failureLimit > 0;
    }

    public function unlockWindowSeconds(): int
    {
        return max(0, $this->unlockMinutes) * 60;
    }

    public function shouldLock(int $failedAttempts): bool
    {
        return $this->isEnabled() && $failedAttempts >= $this->failureLimit;
    }

    /**
     * Native policy uses strict expiry ($elapsed > $window) and treats zero or
     * negative unlock minutes as manual-unlock only.
     */
    public function hasLockoutExpired(int $lastFailureTimestamp, int $nowTimestamp): bool
    {
        if (!$this->isEnabled() || $this->unlockMinutes <= 0) {
            return false;
        }

        return ($nowTimestamp - $lastFailureTimestamp) > $this->unlockWindowSeconds();
    }
}
