<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Port;

use Kadupul\IdentityAccess\Domain\CredentialGeneration;
use Kadupul\IdentityAccess\Domain\Policy\LockoutPolicy;
use Kadupul\IdentityAccess\Domain\Policy\PasswordComplexityPolicy;

/**
 * Outbound persistence port for local credential verification and password changes.
 */
interface PasswordCredentialStore
{
    public function findLocalAccountByUsername(string $username): ?LocalPasswordAccount;

    public function findAccountById(int $userId): ?LocalPasswordAccount;

    /**
     * Updates a rehashed password hash without changing the active credential
     * generation (matches auth_rehash_password_preserving_sessions()).
     */
    public function updatePasswordPreservingSessions(
        int $userId,
        #[\SensitiveParameter]
        string $expectedStoredHash,
        #[\SensitiveParameter]
        string $newStoredHash,
    ): bool;

    /**
     * Updates a user's password hash, clears must_change_password, rotates
     * auth_credential_generation, and removes persisted remembered tokens/sessions.
     */
    public function changePasswordAndRotateGeneration(
        int $userId,
        #[\SensitiveParameter]
        string $expectedStoredHash,
        #[\SensitiveParameter]
        string $newStoredHash,
        CredentialGeneration $nextGeneration,
    ): bool;

    public function requirePasswordChange(int $userId): void;

    public function recordLastLogin(int $userId): void;

    public function lockoutPolicy(): LockoutPolicy;

    public function complexityPolicy(): PasswordComplexityPolicy;
}
