<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Application\Port\LocalCredentials;
use RuntimeException;

/** Requires the native bootstrap; does not open a second database or session. */
final readonly class NativeLocalCredentials implements LocalCredentials
{
    public function verifyPassword(string $username): array
    {
        if (!function_exists('secpass_login_process')) {
            throw new RuntimeException('Native password policy is unavailable.');
        }
        $account = \secpass_login_process($username);
        if (!is_array($account)) {
            throw new RuntimeException('Native password policy returned an invalid result.');
        }
        return $account;
    }

    public function verificationFailed(): bool
    {
        return (bool) ($GLOBALS['error'] ?? false);
    }

    public function reload(string $username): array
    {
        if (!function_exists('db_fetch_row_prepared')) {
            throw new RuntimeException('Native account persistence is unavailable.');
        }
        $account = \db_fetch_row_prepared(
            'SELECT * FROM user_auth WHERE username = ? AND realm = 0',
            [$username],
        );
        return is_array($account) ? $account : [];
    }

    public function rehashIfNeeded(array $account, string $verifiedHash): bool
    {
        if (!function_exists('compat_password_needs_rehash')) {
            throw new RuntimeException('Native password rehash policy is unavailable.');
        }
        if (!\compat_password_needs_rehash($verifiedHash, PASSWORD_DEFAULT)) {
            return true;
        }

        if (!function_exists('compat_password_hash') || !function_exists('get_nfilter_request_var')
            || !function_exists('db_check_password_length') || !function_exists('auth_rehash_password_preserving_sessions')) {
            throw new RuntimeException('Native password rehash persistence is unavailable.');
        }
        $password = \compat_password_hash(\get_nfilter_request_var('login_password'), PASSWORD_DEFAULT);
        \db_check_password_length();
        return \auth_rehash_password_preserving_sessions($account['id'], $verifiedHash, $password) === true;
    }

    public function deny(): void
    {
        if (!function_exists('__')) {
            throw new RuntimeException('Native authentication translations are unavailable.');
        }
        $GLOBALS['error'] = true;
        $GLOBALS['error_msg'] = \__('Access Denied!  Login Failed.');
    }
}
