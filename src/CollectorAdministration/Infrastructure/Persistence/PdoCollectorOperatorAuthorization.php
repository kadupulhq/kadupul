<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Persistence;

final class PdoCollectorOperatorAuthorization
{
    public function assertCanManage(\PDO $connection, int $actorId): void
    {
        if ($actorId < 1 || !$connection->inTransaction()) {
            throw new CollectorBulkAccessDenied();
        }
        $lock = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' LOCK IN SHARE MODE' : '';
        $authMethod = $connection->query("SELECT value FROM settings WHERE name = 'auth_method'$lock")->fetchColumn();
        if ($authMethod !== false && !in_array((int) $authMethod, [1, 2, 3, 4], true)) {
            throw new CollectorBulkAccessDenied();
        }
        $user = $connection->prepare('SELECT id, username, enabled, locked, must_change_password FROM user_auth WHERE id = ?' . $lock);
        $user->execute([$actorId]);
        $user = $user->fetch(\PDO::FETCH_ASSOC);
        if (!$user || $user['enabled'] !== 'on' || $user['locked'] === 'on' || ($user['must_change_password'] ?? '') === 'on') {
            throw new CollectorBulkAccessDenied();
        }
        $guest = $connection->query("SELECT value FROM settings WHERE name = 'guest_user'$lock")->fetchColumn();
        if ($actorId === (int) $guest || $user['username'] === $guest
            || !$this->hasRealm($connection, $actorId, 8, $lock)
            || !$this->hasRealm($connection, $actorId, 3, $lock)) {
            throw new CollectorBulkAccessDenied();
        }
    }

    private function hasRealm(\PDO $connection, int $actorId, int $realmId, string $lock): bool
    {
        $query = $connection->prepare('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?' . $lock);
        $query->execute([$actorId, $realmId]);
        if ($query->fetchColumn() !== false) {
            return true;
        }
        $query = $connection->prepare("SELECT r.realm_id FROM user_auth_group_realm r
            INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
            INNER JOIN user_auth_group g ON g.id = r.group_id
            WHERE g.enabled = 'on' AND m.user_id = ? AND r.realm_id = ? LIMIT 1$lock");
        $query->execute([$actorId, $realmId]);
        return $query->fetchColumn() !== false;
    }
}
