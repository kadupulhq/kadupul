<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Application\Port\AccountLockoutRecovery;
use RuntimeException;

/** Retains the caller's native connection, settings cache and transaction. */
final readonly class NativeAccountLockoutRecovery implements AccountLockoutRecovery
{
    public function find(string $username, int|string $realm): ?array
    {
        if (!function_exists('db_fetch_row_prepared')) {
            throw new RuntimeException('Native account lookup is unavailable.');
        }
        $user = \db_fetch_row_prepared(
            "SELECT id, username, lastfail, failed_attempts, `locked`, password
                FROM user_auth
                WHERE username = ?
                AND realm = ?
                AND enabled = 'on'",
            [$username, $realm],
        );
        if (!is_array($user) || $user === []) {
            return null;
        }
        // Both columns are non-null integers in the native schema; PDO may return strings.
        if (!is_scalar($user['lastfail'] ?? null) || !is_scalar($user['failed_attempts'] ?? null)) {
            throw new RuntimeException('Native lockout state is invalid.');
        }
        return ['lastfail' => (int) $user['lastfail'], 'failed_attempts' => (int) $user['failed_attempts']];
    }

    public function unlockMinutes(): int
    {
        if (!function_exists('read_config_option')) {
            throw new RuntimeException('Native authentication settings are unavailable.');
        }
        $minutes = \read_config_option('secpass_unlocktime');
        if (!is_scalar($minutes) && $minutes !== null) {
            throw new RuntimeException('Native unlock interval must be a scalar setting.');
        }
        return (int) $minutes;
    }

    public function recordCheck(string $username, int $elapsed, int $window): void
    {
        if (!function_exists('cacti_log')) {
            throw new RuntimeException('Native authentication logging is unavailable.');
        }
        \cacti_log("DEBUG: User '$username' secs_fail = $elapsed, secs_unlock = $window", false, 'AUTH', \constant('POLLER_VERBOSITY_DEBUG'));
    }

    public function clear(string $username, int|string $realm, int $lastFailure, int $failures): void
    {
        if (!function_exists('db_execute_prepared')) {
            throw new RuntimeException('Native account update is unavailable.');
        }
        // Compare in the mutation itself. A failure recorded after find() must
        // survive recovery, including another failure within the same second.
        \db_execute_prepared(
            "UPDATE user_auth
                SET lastfail = 0, failed_attempts = 0, `locked` = ''
                WHERE username = ?
                AND realm = ?
                AND enabled = 'on'
                AND lastfail = ?
                AND failed_attempts = ?",
            [$username, $realm, $lastFailure, $failures],
        );
    }
}
