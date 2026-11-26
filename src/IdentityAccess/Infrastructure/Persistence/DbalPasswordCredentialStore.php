<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kadupul\IdentityAccess\Application\Port\LocalPasswordAccount;
use Kadupul\IdentityAccess\Application\Port\PasswordCredentialStore;
use Kadupul\IdentityAccess\Domain\CredentialGeneration;
use Kadupul\IdentityAccess\Domain\Policy\LockoutPolicy;
use Kadupul\IdentityAccess\Domain\Policy\PasswordComplexityPolicy;
use Kadupul\IdentityAccess\Domain\User;

/**
 * Doctrine DBAL persistence adapter for local password authentication,
 * session-preserving password rehashing, and atomic password changes with
 * credential-generation rotation.
 */
final readonly class DbalPasswordCredentialStore implements PasswordCredentialStore
{
    public function __construct(
        private Connection $database,
    ) {}

    #[\Override]
    public function findLocalAccountByUsername(string $username): ?LocalPasswordAccount
    {
        $row = $this->database->fetchAssociative(
            'SELECT id, username, realm, password, enabled, locked, must_change_password, password_change, failed_attempts, lastfail, full_name, email_address
             FROM user_auth
             WHERE username = ? AND realm = 0',
            [$username],
        );

        return $row === false ? null : $this->hydrateAccount($row);
    }

    #[\Override]
    public function findAccountById(int $userId): ?LocalPasswordAccount
    {
        if ($userId < 1) {
            return null;
        }

        $row = $this->database->fetchAssociative(
            'SELECT id, username, realm, password, enabled, locked, must_change_password, password_change, failed_attempts, lastfail, full_name, email_address
             FROM user_auth
             WHERE id = ?',
            [$userId],
        );

        return $row === false ? null : $this->hydrateAccount($row);
    }

    #[\Override]
    public function updatePasswordPreservingSessions(
        int $userId,
        #[\SensitiveParameter]
        string $expectedStoredHash,
        #[\SensitiveParameter]
        string $newStoredHash,
    ): bool {
        if ($userId < 1 || $this->database->isTransactionActive()) {
            return false;
        }

        $this->database->beginTransaction();

        try {
            $lock = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : '';
            $currentHash = $this->database->fetchOne('SELECT password FROM user_auth WHERE id = ?' . $lock, [$userId]);
            if (!is_string($currentHash) || !hash_equals($expectedStoredHash, $currentHash)) {
                $this->database->rollBack();

                return false;
            }

            $existingGenerationHex = DbalSessionCredentialGeneration::forAccount($this->database, $userId, $currentHash);
            $generation = CredentialGeneration::fromHex($existingGenerationHex);

            $updated = $this->database->executeStatement(
                'UPDATE user_auth SET password = ? WHERE id = ? AND password = ?',
                [$newStoredHash, $userId, $expectedStoredHash],
            );
            if ($updated !== 1) {
                $this->database->rollBack();

                return false;
            }

            // Preserve the active generation under the rehashed password fingerprint so
            // existing sessions and v1 remembered tokens survive an automatic hash upgrade.
            $this->database->executeStatement(
                "REPLACE INTO settings_user (user_id, name, value) VALUES (?, 'auth_credential_generation', ?)",
                [$userId, $generation->mappingFor($newStoredHash)],
            );

            $this->database->commit();

            return true;
        } catch (\Throwable) {
            if ($this->database->isTransactionActive()) {
                try {
                    $this->database->rollBack();
                } catch (\Throwable) {
                    // Preserve original failure.
                }
            }

            return false;
        }
    }

    #[\Override]
    public function changePasswordAndRotateGeneration(
        int $userId,
        #[\SensitiveParameter]
        string $expectedStoredHash,
        #[\SensitiveParameter]
        string $newStoredHash,
        CredentialGeneration $nextGeneration,
    ): bool {
        if ($userId < 1 || $this->database->isTransactionActive()) {
            return false;
        }

        $this->database->beginTransaction();

        try {
            $lock = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ' FOR UPDATE' : '';
            $currentHash = $this->database->fetchOne('SELECT password FROM user_auth WHERE id = ?' . $lock, [$userId]);
            if (!is_string($currentHash) || !hash_equals($expectedStoredHash, $currentHash)) {
                $this->database->rollBack();

                return false;
            }

            $updated = $this->database->executeStatement(
                "UPDATE user_auth SET password = ?, must_change_password = '' WHERE id = ? AND password = ?",
                [$newStoredHash, $userId, $expectedStoredHash],
            );
            if ($updated !== 1) {
                $this->database->rollBack();

                return false;
            }

            $this->database->executeStatement(
                "REPLACE INTO settings_user (user_id, name, value) VALUES (?, 'auth_credential_generation', ?)",
                [$userId, $nextGeneration->mappingFor($newStoredHash)],
            );
            $this->database->executeStatement('DELETE FROM user_auth_cache WHERE user_id = ?', [$userId]);
            $this->database->executeStatement('DELETE FROM sessions WHERE user_id = ?', [$userId]);

            $this->database->commit();

            return true;
        } catch (\Throwable) {
            if ($this->database->isTransactionActive()) {
                try {
                    $this->database->rollBack();
                } catch (\Throwable) {
                    // Preserve original failure.
                }
            }

            return false;
        }
    }

    #[\Override]
    public function requirePasswordChange(int $userId): void
    {
        $this->database->executeStatement(
            "UPDATE user_auth SET must_change_password = 'on' WHERE id = ?",
            [$userId],
        );
    }

    #[\Override]
    public function recordLastLogin(int $userId): void
    {
        $this->database->executeStatement(
            'UPDATE user_auth SET lastlogin = ? WHERE id = ?',
            [time(), $userId],
        );
    }

    #[\Override]
    public function lockoutPolicy(): LockoutPolicy
    {
        $limit = $this->intSetting('secpass_lockfailed', 0);
        $unlockMinutes = $this->intSetting('secpass_unlocktime', 60);

        return new LockoutPolicy($limit, $unlockMinutes);
    }

    #[\Override]
    public function complexityPolicy(): PasswordComplexityPolicy
    {
        $enforced = $this->stringSetting('secpass_forcecomplex', '') === 'on';
        $minLength = $this->intSetting('secpass_minlen', 8);
        $reqNum = $this->stringSetting('secpass_reqnum', 'on') === 'on';
        $reqMixCase = $this->stringSetting('secpass_reqmixcase', 'on') === 'on';
        $reqSpec = $this->stringSetting('secpass_reqspec', 'on') === 'on';

        return new PasswordComplexityPolicy($enforced, $minLength, $reqNum, $reqMixCase, $reqSpec);
    }

    /** @param array<string, mixed> $row */
    private function hydrateAccount(array $row): LocalPasswordAccount
    {
        $id = self::asInt($row['id'] ?? 0);
        $username = self::asString($row['username'] ?? '');
        $realm = self::asInt($row['realm'] ?? 0);
        $passwordHash = self::asString($row['password'] ?? '');
        $generationHex = DbalSessionCredentialGeneration::forAccount($this->database, $id, $passwordHash);
        $generation = $generationHex !== ''
            ? CredentialGeneration::fromHex($generationHex)
            : CredentialGeneration::fromStoredPassword($passwordHash);

        $guest = $this->stringSetting('guest_user', '');
        $isGuest = $guest !== '' && ($id === (int) $guest || $username === $guest);

        $user = new User(
            id: $id,
            username: $username,
            accountRealm: $realm,
            credentialGeneration: $generation,
            enabled: ($row['enabled'] ?? '') === 'on',
            locked: ($row['locked'] ?? '') === 'on',
            mustChangePassword: ($row['must_change_password'] ?? '') === 'on',
            passwordChangeAllowed: ($row['password_change'] ?? '') === 'on',
            failedAttempts: max(0, self::asInt($row['failed_attempts'] ?? 0)),
            lastFailureAt: max(0, self::asInt($row['lastfail'] ?? 0)),
            fullName: self::asString($row['full_name'] ?? ''),
            emailAddress: self::asString($row['email_address'] ?? ''),
        );

        return new LocalPasswordAccount($user, $passwordHash, $isGuest, $generation);
    }

    private function stringSetting(string $name, string $default): string
    {
        $value = $this->database->fetchOne('SELECT value FROM settings WHERE name = ?', [$name]);

        return is_string($value) ? $value : $default;
    }

    private function intSetting(string $name, int $default): int
    {
        $value = $this->database->fetchOne('SELECT value FROM settings WHERE name = ?', [$name]);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private static function asInt(mixed $value): int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : 0;
    }

    private static function asString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
