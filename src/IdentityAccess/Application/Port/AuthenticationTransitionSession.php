<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

interface AuthenticationTransitionSession
{
    public function takeCookieCredential(): mixed;

    public function isActive(): bool;

    public function regenerate(): bool;

    public function clearPermissionCaches(): void;

    public function bindCredential(int $userId): void;

    public function bindRestoredCredential(string $generation): void;

    public function logBlocked(int $userId, string $reason): void;

    public function logTransitionFailure(int $userId, string $reason): void;

    public function logCompleted(int $userId, string $reason): void;
}
