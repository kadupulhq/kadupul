<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Application\Port\AuthenticatedSession;
use Kadupul\IdentityAccess\Contract\Actor;

final class LegacyAuthenticatedSession implements AuthenticatedSession
{
    public function consoleActor(): ?Actor
    {
        // Only the legacy entry point may attest that authentication completed.
        if (!defined('KADUPUL_AUTHENTICATED_ENTRY') || KADUPUL_AUTHENTICATED_ENTRY !== true
            || session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $id = (int) ($_SESSION['sess_user_id'] ?? 0);
        if ($id <= 0 || $id === (int) get_guest_account() || isset($_SESSION['sess_change_password'])) {
            return null;
        }

        $user = db_fetch_row_prepared('SELECT id, username, enabled, locked FROM user_auth WHERE id = ?', [$id]);
        if (!$user || $user['enabled'] !== 'on' || $user['locked'] === 'on'
            || !cacti_authorize_has_realm($id, 8)) {
            return null;
        }

        return new Actor($id, $user['username']);
    }
}
