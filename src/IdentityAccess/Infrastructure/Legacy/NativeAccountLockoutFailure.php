<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Application\Port\AccountLockoutFailure;

final readonly class NativeAccountLockoutFailure implements AccountLockoutFailure
{
    public function find(string $username, int|string $realm): ?array
    {
        if (!function_exists('db_fetch_row_prepared')) {
            throw new \RuntimeException('Native account lookup is unavailable.');
        }
        $user = \db_fetch_row_prepared(
            "SELECT id, enabled, `locked` FROM user_auth WHERE username = ? AND realm = ?",
            [$username, $realm],
        );
        if (!is_array($user) || $user === [] || !isset($user['id']) || !is_scalar($user['id'])
            || !isset($user['enabled']) || !is_scalar($user['enabled'])
            || !isset($user['locked']) || !is_scalar($user['locked'])) {
            return null;
        }
        return ['id' => is_int($user['id']) ? $user['id'] : (string) $user['id'], 'enabled' => (string) $user['enabled'], 'locked' => (string) $user['locked']];
    }

    public function increment(string $username, int|string $realm, int $timestamp): void
    {
        if (!function_exists('db_execute_prepared')) {
            throw new \RuntimeException('Native account update is unavailable.');
        }
        \db_execute_prepared(
            'UPDATE user_auth SET lastfail = ?, failed_attempts = failed_attempts + 1 WHERE username = ? AND realm = ?',
            [$timestamp, $username, $realm],
        );
    }

    public function failedAttempts(string $username, int|string $realm): mixed
    {
        if (!function_exists('db_fetch_cell_prepared')) {
            throw new \RuntimeException('Native account lookup is unavailable.');
        }
        return \db_fetch_cell_prepared(
            "SELECT failed_attempts FROM user_auth WHERE username = ? AND realm = ? AND enabled = 'on'",
            [$username, $realm],
        );
    }

    public function recordProgress(string $username, mixed $failedAttempts, int $limit): void
    {
        if (!function_exists('cacti_log')) {
            throw new \RuntimeException('Native authentication logging is unavailable.');
        }
        $progress = is_scalar($failedAttempts) || $failedAttempts === null ? (string) $failedAttempts : '';
        \cacti_log("LOGIN FAILED: User '$username' failed authentication, incrementing lockout ($progress of $limit)", false, 'AUTH', \constant('POLLER_VERBOSITY_LOW'));
    }

    public function lock(string $username, int|string $realm): void
    {
        if (!function_exists('db_execute_prepared')) {
            throw new \RuntimeException('Native account update is unavailable.');
        }
        \db_execute_prepared(
            "UPDATE user_auth SET `locked` = 'on' WHERE username = ? AND realm = ? AND enabled = 'on'",
            [$username, $realm],
        );
    }

    public function recordAudit(string $username, int|string $userId, string $address): void
    {
        if (!function_exists('db_execute_prepared')) {
            throw new \RuntimeException('Native authentication audit is unavailable.');
        }
        \db_execute_prepared(
            'INSERT IGNORE INTO user_log (username, user_id, result, ip, time) VALUES (?, ?, 0, ?, NOW())',
            [$username, $userId, $address],
        );
    }
}
