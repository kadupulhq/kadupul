<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Application\Port\AccountLockoutStatus;
use RuntimeException;

/** Uses the native connection and settings cache without booting another kernel. */
final readonly class NativeAccountLockoutStatus implements AccountLockoutStatus
{
    public function failureLimit(): int
    {
        if (!function_exists('read_config_option')) {
            throw new RuntimeException('Native authentication settings are unavailable.');
        }
        $limit = \read_config_option('secpass_lockfailed');
        if (!is_scalar($limit) && $limit !== null) {
            throw new RuntimeException('Native account lockout limit must be a scalar setting.');
        }
        // Retain both native comparisons, including truncation of fractional settings.
        return $limit > 0 ? (int) $limit : 0;
    }

    public function hasLockedEnabledAccount(string $username, int|string $realm): bool
    {
        if (!function_exists('db_fetch_row_prepared') || !function_exists('cacti_sizeof')) {
            throw new RuntimeException('Native account lockout lookup is unavailable.');
        }
        $user = \db_fetch_row_prepared(
            "SELECT id, username, lastfail, failed_attempts, `locked`, password
                FROM user_auth
                WHERE username = ?
                AND realm = ?
                AND locked = 'on'
                AND enabled = 'on'",
            [$username, $realm],
        );
        return \cacti_sizeof($user) > 0;
    }
}
