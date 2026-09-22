<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use PDO;

/** Lock every grant and policy input used by a device-management write. */
final class DeviceWriteAuthorization
{
    public function allows(PDO $connection, int $actorId): bool
    {
        $policy = $connection->query("SELECT name, value FROM settings WHERE name IN ('auth_method', 'guest_user') LOCK IN SHARE MODE")->fetchAll(PDO::FETCH_KEY_PAIR);
        $query = $connection->prepare('SELECT id, username, enabled, locked FROM user_auth WHERE id = ? FOR UPDATE');
        $query->execute([$actorId]);
        $actor = $query->fetch(PDO::FETCH_ASSOC);
        $hasRealm = static function (int $realm) use ($connection, $actorId): bool {
            $query = $connection->prepare('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ? LOCK IN SHARE MODE');
            $query->execute([$actorId, $realm]);
            if ($query->fetchColumn() !== false) {
                return true;
            }
            $query = $connection->prepare("SELECT r.realm_id FROM user_auth_group_realm r
                INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
                INNER JOIN user_auth_group g ON g.id = r.group_id
                WHERE g.enabled = 'on' AND m.user_id = ? AND r.realm_id = ? LIMIT 1 LOCK IN SHARE MODE");
            $query->execute([$actorId, $realm]);
            return $query->fetchColumn() !== false;
        };
        $guest = $policy['guest_user'] ?? '0';
        return !(!$actor || $actor['enabled'] !== 'on' || $actor['locked'] === 'on'
        || !in_array((int) ($policy['auth_method'] ?? 1), [1, 2, 3, 4], true)
        || (int) $guest === $actorId || $guest === $actor['username']
        || !$hasRealm(8) || !$hasRealm(3));
    }
}
