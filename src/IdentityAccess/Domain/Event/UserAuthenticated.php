<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain\Event;

use Kadupul\IdentityAccess\Domain\AuthMethod;

/** Recorded when an account completes credential verification. */
final readonly class UserAuthenticated
{
    public function __construct(
        public int $userId,
        public string $username,
        public int $accountRealm,
        public AuthMethod $method,
        public string $clientAddress,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
