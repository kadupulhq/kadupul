<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Application\Port\AuthenticationTransitionSession;
use RuntimeException;

final readonly class NativeAuthenticationTransitionSession implements AuthenticationTransitionSession
{
    public function takeCookieCredential(): mixed
    {
        $restored = $_SESSION['sess_cookie_credential'] ?? null;
        unset($_SESSION['sess_cookie_credential']);
        return $restored;
    }

    public function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function regenerate(): bool
    {
        return \session_regenerate_id(true);
    }

    public function clearPermissionCaches(): void
    {
        if (!function_exists('kill_session_var')) {
            throw new RuntimeException('Native permission cache cleanup is unavailable.');
        }
        \kill_session_var('sess_user_realms');
        \kill_session_var('sess_user_config_array');
        \kill_session_var('sess_config_array');
    }

    public function bindCredential(int $userId): void
    {
        if (!function_exists('auth_session_bind_credentials')) {
            throw new RuntimeException('Native credential binding is unavailable.');
        }
        \auth_session_bind_credentials($userId);
    }

    public function bindRestoredCredential(string $generation): void
    {
        $_SESSION['sess_user_credential'] = $generation;
    }

    public function logBlocked(int $userId, string $reason): void
    {
        if (!function_exists('cacti_log')) {
            throw new RuntimeException('Native authentication logging is unavailable.');
        }
        \cacti_log('SECURITY: auth transition blocked for unavailable, disabled or locked user: ' . $userId . ' reason: ' . $reason, false, 'AUTH');
    }

    public function logTransitionFailure(int $userId, string $reason): void
    {
        if (!function_exists('cacti_log')) {
            throw new RuntimeException('Native authentication logging is unavailable.');
        }
        \cacti_log('SECURITY: auth transition could not regenerate the session for user ' . $userId . ' reason: ' . $reason, false, 'AUTH');
    }

    public function logCompleted(int $userId, string $reason): void
    {
        if (!function_exists('cacti_log')) {
            throw new RuntimeException('Native authentication logging is unavailable.');
        }
        \cacti_log('NOTE: auth transition completed for user ' . $userId . ' reason=' . $reason, false, 'AUTH', \constant('POLLER_VERBOSITY_MEDIUM'));
    }
}
