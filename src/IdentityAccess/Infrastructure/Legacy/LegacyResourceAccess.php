<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\ResourceAccess;
use Kadupul\Platform\Contract\DatabaseConnection;

/** Resource ownership complements the caller's authenticated console boundary. */
final readonly class LegacyResourceAccess implements ResourceAccess
{
    public function __construct(private DatabaseConnection $database) {}
    public function canManageTree(int $actorId, int $ownerId): bool
    {
        return $this->realm($actorId, 1, false) || ($this->realm($actorId, 4) && $actorId === $ownerId);
    }
    public function canManageReport(int $actorId, int $ownerId): bool
    {
        return $this->realm($actorId, 1, false) || $this->realm($actorId, 21)
            || ($this->realm($actorId, 22) && $actorId === $ownerId);
    }
    private function realm(int $actorId, int $realmId, bool $groups = true): bool
    {
        if ($actorId < 1) {
            return false;
        }
        $db = $this->database->get();
        $lock = $db->inTransaction() ? ' LOCK IN SHARE MODE' : '';
        $query = $db->prepare('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?' . $lock);
        $query->execute([$actorId, $realmId]);
        if ($query->fetchColumn() !== false) {
            return true;
        }
        if (!$groups) {
            return false;
        }
        $query = $db->prepare("SELECT r.realm_id FROM user_auth_group_realm r INNER JOIN user_auth_group_members m ON m.group_id = r.group_id INNER JOIN user_auth_group g ON g.id = r.group_id WHERE g.enabled = 'on' AND m.user_id = ? AND r.realm_id = ? LIMIT 1" . $lock);
        $query->execute([$actorId, $realmId]);
        return $query->fetchColumn() !== false;
    }
}
