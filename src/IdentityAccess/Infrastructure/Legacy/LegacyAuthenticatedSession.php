<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Application\Port\AuthenticatedSession;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacyAuthenticatedSession implements AuthenticatedSession, ConsoleAccess
{
    public function __construct(private SharedSession $session, private DatabaseConnection $database) {}

    public function consoleActor(): ?Actor
    {
        $snapshot = $this->session->read();
        $id = (int) ($snapshot['sess_user_id'] ?? 0);
        if ($id <= 0 || isset($snapshot['sess_change_password'])) {
            return null;
        }
        $authMethod = $this->database->get()->query("SELECT value FROM settings WHERE name = 'auth_method'" . $this->readLock())->fetchColumn();
        if ($authMethod !== false && !in_array((int) $authMethod, [1, 2, 3, 4], true)) {
            return null;
        }
        $query = $this->database->get()->prepare('SELECT id, username, enabled, locked FROM user_auth WHERE id = ?' . $this->readLock());
        $query->execute([$id]);
        $user = $query->fetch();
        if (!$user || $user['enabled'] !== 'on' || $user['locked'] === 'on') {
            $this->session->revoke();
            return null;
        }
        $guest = $this->database->get()->query("SELECT value FROM settings WHERE name = 'guest_user'" . $this->readLock())->fetchColumn();
        if ($id === (int) $guest || $user['username'] === $guest || !$this->hasRealm($id, 8)) {
            return null;
        }
        return new Actor($id, $user['username']);
    }

    public function canManageDevices(Actor $actor): bool
    {
        return $actor->id > 0 && $this->hasRealm($actor->id, 3);
    }

    private function hasRealm(int $id, int $realm): bool
    {
        // Each successful grant must remain locked until its caller commits.
        // Separate queries ensure the lock applies to direct AND group grants;
        // a suffix on a UNION would not lock every query block.
        $query = $this->database->get()->prepare('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?' . $this->readLock());
        $query->execute([$id, $realm]);
        if ($query->fetchColumn() !== false) {
            return true;
        }
        $query = $this->database->get()->prepare("SELECT r.realm_id FROM user_auth_group_realm r
            INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
            INNER JOIN user_auth_group g ON g.id = r.group_id
            WHERE g.enabled = 'on' AND m.user_id = ? AND r.realm_id = ? LIMIT 1" . $this->readLock());
        $query->execute([$id, $realm]);
        return $query->fetchColumn() !== false;
    }

    private function readLock(): string
    {
        // MySQL and MariaDB locking reads see the current committed row even
        // after an earlier consistent read, and hold it through the site write.
        return $this->database->get()->inTransaction() ? ' LOCK IN SHARE MODE' : '';
    }
}
