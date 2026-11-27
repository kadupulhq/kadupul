<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

/**
 * Framework-free password verification and hashing boundary preserving legacy
 * MD5 fallback verification, rehash checks, and unknown-user timing padding.
 */
interface PasswordVerifier
{
    public function verify(string $storedHash, #[\SensitiveParameter] string $plainPassword): bool;

    public function hash(#[\SensitiveParameter] string $plainPassword): string;

    public function needsRehash(string $storedHash): bool;

    public function padUnknownUserTiming(): void;
}
