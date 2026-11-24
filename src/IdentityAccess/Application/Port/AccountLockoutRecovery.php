<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

interface AccountLockoutRecovery
{
    /** @return array{lastfail: int, failed_attempts: int}|null Enabled account snapshot. */
    public function find(string $username, int|string $realm): ?array;

    public function unlockMinutes(): int;

    public function recordCheck(string $username, int $elapsed, int $window): void;

    /** Preserve any failure or disablement recorded after the snapshot. */
    public function clear(string $username, int|string $realm, int $lastFailure, int $failures): void;
}
