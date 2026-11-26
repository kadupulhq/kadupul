<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain\Event;

use Kadupul\IdentityAccess\Domain\CredentialGeneration;

/** Recorded when all active sessions and remembered tokens for a user are invalidated. */
final readonly class CredentialsRevoked
{
    public function __construct(
        public int $userId,
        public CredentialGeneration $newGeneration,
        public string $reason,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
