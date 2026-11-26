<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

use Kadupul\IdentityAccess\Domain\CredentialGeneration;
use Kadupul\IdentityAccess\Domain\User;

/**
 * Local credential snapshot loaded for authentication and password-change use cases.
 */
final readonly class LocalPasswordAccount
{
    public function __construct(
        public User $user,
        #[\SensitiveParameter]
        private string $storedPasswordHash,
        public bool $isGuestAccount,
        public CredentialGeneration $generation,
    ) {}

    public function storedPasswordHash(): string
    {
        return $this->storedPasswordHash;
    }
}
