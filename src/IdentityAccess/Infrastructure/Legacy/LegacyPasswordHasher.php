<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/** Preserve native password bytes and costs while exposing Symfony's contract. */
final readonly class LegacyPasswordHasher implements PasswordHasherInterface
{
    /** @param array<string, mixed> $options Native password_hash options. */
    public function __construct(
        private string|int|null $algorithm = PASSWORD_DEFAULT,
        private array $options = [],
    ) {}

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        // Symfony's default hasher prehashes long/NUL bcrypt input. Existing
        // native credentials require PHP's original bytes and error semantics.
        return password_hash($plainPassword, $this->algorithm, $this->options);
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
    {
        if (password_get_info($hashedPassword)['algo'] === null) {
            // Keep legacy/invalid records aligned with unknown-account work.
            // Client input may contain NUL, so padding always uses safe bytes.
            password_hash('kadupul-login-timing-padding', PASSWORD_DEFAULT);
        }

        return password_verify($plainPassword, $hashedPassword)
            || hash_equals(md5($plainPassword), $hashedPassword);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return password_needs_rehash($hashedPassword, $this->algorithm, $this->options);
    }
}
