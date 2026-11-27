<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

/**
 * Additive login rate limiter checking the per-address bucket first before
 * allocating or touching any per-username-and-realm bucket.
 */
interface LoginRateLimiter
{
    public function consume(string $clientAddress, string $username, int $accountRealm): LoginRateLimitDecision;

    public function resetAccount(string $username, int $accountRealm): void;
}
