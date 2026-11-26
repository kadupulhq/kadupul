<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony\Security;

use Kadupul\IdentityAccess\Application\Port\PasswordVerifier;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Adapts Symfony's PasswordHasherInterface (backed by LegacyPasswordHasher) to
 * the framework-free PasswordVerifier application port.
 */
final readonly class SymfonyPasswordVerifier implements PasswordVerifier
{
    public function __construct(
        private PasswordHasherInterface $hasher,
    ) {}

    #[\Override]
    public function verify(string $storedHash, #[\SensitiveParameter] string $plainPassword): bool
    {
        return $this->hasher->verify($storedHash, $plainPassword);
    }

    #[\Override]
    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        return $this->hasher->hash($plainPassword);
    }

    #[\Override]
    public function needsRehash(string $storedHash): bool
    {
        return $this->hasher->needsRehash($storedHash);
    }

    #[\Override]
    public function padUnknownUserTiming(): void
    {
        // Performs password-hash work equivalent to verifying a missing or
        // unhashable account without exposing client bytes to NUL truncation.
        $this->hasher->hash('kadupul-login-timing-padding');
    }
}
