<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

use Kadupul\IdentityAccess\Domain\CredentialGeneration;

/**
 * Atomically bumps auth_credential_generation and purges persisted sessions
 * and remembered tokens for an account.
 */
interface SessionCredentialRevoker
{
    public function revokeAll(
        int $userId,
        CredentialGeneration $expectedCurrentGeneration,
        CredentialGeneration $nextGeneration,
    ): bool;
}
