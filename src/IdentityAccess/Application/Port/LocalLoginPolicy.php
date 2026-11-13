<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

/** Settings and native effects after a local password has been verified. */
interface LocalLoginPolicy
{
    public function requiresComplexity(): bool;

    public function satisfiesComplexity(#[\SensitiveParameter] string $password): bool;

    /** Preserve native account-ID binding and password-change notification. */
    public function requirePasswordChange(mixed $userId): void;

    public function denyComplexity(string $username): void;

    public function tracksLastLogin(): bool;

    public function recordLastLogin(string $username): void;
}
