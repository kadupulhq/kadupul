<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Domain\CredentialGeneration;
use Kadupul\IdentityAccess\Domain\Event\CredentialsRevoked;

/**
 * Immutable outcome of a Logout Everywhere / credential-generation revocation.
 */
final readonly class SessionRevocationResult
{
    private function __construct(
        public bool $revoked,
        public ?CredentialGeneration $newGeneration = null,
        public ?CredentialsRevoked $event = null,
    ) {}

    public static function revoked(CredentialGeneration $newGeneration, CredentialsRevoked $event): self
    {
        return new self(true, $newGeneration, $event);
    }

    public static function rejected(): self
    {
        return new self(false);
    }
}
