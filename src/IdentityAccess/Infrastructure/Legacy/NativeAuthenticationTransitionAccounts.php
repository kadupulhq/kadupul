<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Application\Port\AuthenticationTransitionAccounts;
use RuntimeException;

final readonly class NativeAuthenticationTransitionAccounts implements AuthenticationTransitionAccounts
{
    public function find(int $userId): ?array
    {
        if (!function_exists('db_fetch_row_prepared')) {
            throw new RuntimeException('Native account lookup is unavailable.');
        }
        $account = \db_fetch_row_prepared('SELECT enabled, locked, password FROM user_auth WHERE id = ?', [$userId]);
        if (!is_array($account) || !isset($account['enabled'], $account['locked'], $account['password'])
            || !is_string($account['enabled']) || !is_string($account['locked']) || !is_string($account['password'])) {
            return null;
        }
        return ['enabled' => $account['enabled'], 'locked' => $account['locked'], 'password' => $account['password']];
    }

    public function guestId(): int
    {
        if (!function_exists('get_guest_account')) {
            throw new RuntimeException('Native guest account setting is unavailable.');
        }
        $guestId = \get_guest_account();
        if (!is_int($guestId) && !(is_string($guestId) && preg_match('/^[0-9]+$/D', $guestId) === 1)) {
            throw new RuntimeException('Native guest account setting is invalid.');
        }

        return (int) $guestId;
    }

    public function credentialGeneration(int $userId, string $password): string
    {
        if (!function_exists('auth_session_credential_generation')) {
            throw new RuntimeException('Native credential binding is unavailable.');
        }
        $generation = \auth_session_credential_generation($userId, $password);

        return $generation;
    }
}
