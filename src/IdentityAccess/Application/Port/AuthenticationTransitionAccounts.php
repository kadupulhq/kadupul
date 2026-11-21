<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

interface AuthenticationTransitionAccounts
{
    /** @return array{enabled: string, locked: string, password: string}|null */
    public function find(int $userId): ?array;

    public function guestId(): int;

    public function credentialGeneration(int $userId, string $password): string;
}
