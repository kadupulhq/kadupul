<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

interface AccountLockoutFailure
{
    /** @return array{id: int|string, enabled: string, locked: string}|null */
    public function find(string $username, int|string $realm): ?array;

    public function increment(string $username, int|string $realm, int $timestamp): void;

    public function failedAttempts(string $username, int|string $realm): mixed;

    public function recordProgress(string $username, mixed $failedAttempts, int $limit): void;

    public function lock(string $username, int|string $realm): void;

    public function recordAudit(string $username, int|string $userId, string $address): void;
}
