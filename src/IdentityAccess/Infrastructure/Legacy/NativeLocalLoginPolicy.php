<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Application\Port\LocalLoginPolicy;
use RuntimeException;

/** Uses the native bootstrap, current settings cache and existing connection. */
final readonly class NativeLocalLoginPolicy implements LocalLoginPolicy
{
    public function requiresComplexity(): bool
    {
        if (!function_exists('read_config_option')) {
            throw new RuntimeException('Native authentication settings are unavailable.');
        }
        $required = \read_config_option('secpass_forceold');
        return $required === 'on' || $required === true;
    }

    public function satisfiesComplexity(#[\SensitiveParameter] string $password): bool
    {
        if (!function_exists('secpass_check_pass')) {
            throw new RuntimeException('Native password complexity policy is unavailable.');
        }
        return \secpass_check_pass($password) === 'ok';
    }

    public function requirePasswordChange(mixed $userId): void
    {
        if (!function_exists('db_execute_prepared') || !function_exists('raise_message')
            || !function_exists('__') || !defined('MESSAGE_LEVEL_INFO')) {
            throw new RuntimeException('Native password-change handoff is unavailable.');
        }
        \db_execute_prepared(
            "UPDATE user_auth SET must_change_password = 'on' WHERE id = ? AND realm = 0 AND enabled = 'on'",
            [$userId],
        );
        \raise_message('forced_password', \__('Your Kadupul administrator has forced complex passwords for logins and your current Kadupul password does not match the new requirements.  Therefore, you must change your password now.'), MESSAGE_LEVEL_INFO);
    }

    public function denyComplexity(string $username): void
    {
        if (!function_exists('__') || !function_exists('cacti_log')) {
            throw new RuntimeException('Native authentication failure reporting is unavailable.');
        }
        $GLOBALS['error'] = true;
        $GLOBALS['error_msg'] = \__('Access Denied!  Login Failed.');
        \cacti_log(sprintf('LOGIN FAILED: User %s has a password that fails the complexity rules and may not change it.', $username), false, 'AUTH');
    }

    public function tracksLastLogin(): bool
    {
        if (!function_exists('read_config_option')) {
            throw new RuntimeException('Native authentication settings are unavailable.');
        }
        return \read_config_option('secpass_expireaccount') > 0;
    }

    public function recordLastLogin(string $username): void
    {
        if (!function_exists('db_execute_prepared')) {
            throw new RuntimeException('Native login activity persistence is unavailable.');
        }
        \db_execute_prepared(
            "UPDATE user_auth SET lastlogin = ? WHERE username = ? AND realm = 0 AND enabled = 'on'",
            [time(), $username],
        );
    }
}
