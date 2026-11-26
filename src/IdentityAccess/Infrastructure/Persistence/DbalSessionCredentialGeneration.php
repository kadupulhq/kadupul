<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Resolves the active session credential generation for an account over DBAL,
 * preserving the exact hash and rehash-recheck semantics of
 * auth_session_credential_generation() in lib/auth.php.
 */
final class DbalSessionCredentialGeneration
{
    private const string MAPPING_PATTERN = '/\A[a-f0-9]{64}:[a-f0-9]{64}\z/D';

    public static function key(#[\SensitiveParameter] string $password): string
    {
        return hash('sha256', $password);
    }

    public static function forAccount(
        Connection $database,
        int $userId,
        #[\SensitiveParameter]
        string $password,
    ): string {
        $fingerprint = self::key($password);
        $lock = $database->isTransactionActive() && $database->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? ' LOCK IN SHARE MODE'
            : '';

        $mapping = $database->fetchOne(
            "SELECT value FROM settings_user WHERE user_id = ? AND name = 'auth_credential_generation'" . $lock,
            [$userId],
        );

        if (is_string($mapping) && preg_match(self::MAPPING_PATTERN, $mapping) === 1) {
            if (hash_equals($fingerprint, substr($mapping, 0, 64))) {
                return substr($mapping, 65);
            }

            // The caller may have read the password before a rehash commit and
            // the mapping after it. Re-read both live values together so
            // successive upgrades do not revoke an unchanged credential.
            $live = $database->fetchAssociative(
                "SELECT ua.password, su.value
                FROM user_auth AS ua
                LEFT JOIN settings_user AS su ON su.user_id = ua.id
                    AND su.name = 'auth_credential_generation'
                WHERE ua.id = ?" . $lock,
                [$userId],
            );

            if ($live === false || !array_key_exists('password', $live)) {
                return '';
            }

            $livePassword = $live['password'];
            $fingerprint = self::key(is_string($livePassword) ? $livePassword : '');
            $liveMapping = $live['value'] ?? '';
            if (
                is_string($liveMapping)
                && preg_match(self::MAPPING_PATTERN, $liveMapping) === 1
                && hash_equals($fingerprint, substr($liveMapping, 0, 64))
            ) {
                return substr($liveMapping, 65);
            }
        }

        return $fingerprint;
    }
}
