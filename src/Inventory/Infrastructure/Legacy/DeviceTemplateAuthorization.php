<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Query\InventoryAccessDenied;

final class DeviceTemplateAuthorization
{
    public static function authorize(\PDO $db, int $actor, bool $lock = false): void
    {
        $suffix = $lock ? ' LOCK IN SHARE MODE' : '';
        $settings = DeviceTemplateStatement::fetchAll(DeviceTemplateStatement::query($db, "SELECT name, value FROM settings WHERE name IN ('auth_method', 'guest_user')" . $suffix), \PDO::FETCH_KEY_PAIR);
        $query = DeviceTemplateStatement::prepare($db, 'SELECT id, username, enabled, locked, must_change_password FROM user_auth WHERE id = ?' . $suffix);
        DeviceTemplateStatement::execute($query, [$actor]);
        $row = DeviceTemplateStatement::fetch($query, \PDO::FETCH_ASSOC);
        $guest = $settings['guest_user'] ?? '0';
        if (!$row || $row['enabled'] !== 'on' || $row['locked'] === 'on' || $row['must_change_password'] === 'on'
            || !in_array((int) ($settings['auth_method'] ?? 1), [1, 2, 3, 4], true) || (int) $guest === $actor || $guest === $row['username']) {
            throw new InventoryAccessDenied(false);
        }
        foreach ([8, 12] as $realm) {
            $query = DeviceTemplateStatement::prepare($db, 'SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ?' . $suffix);
            DeviceTemplateStatement::execute($query, [$actor, $realm]);
            if (DeviceTemplateStatement::fetchColumn($query) !== false) {
                continue;
            }
            $query = DeviceTemplateStatement::prepare($db, "SELECT r.realm_id FROM user_auth_group_realm r INNER JOIN user_auth_group_members m ON m.group_id = r.group_id INNER JOIN user_auth_group g ON g.id = r.group_id AND g.enabled = 'on' WHERE m.user_id = ? AND r.realm_id = ? LIMIT 1" . $suffix);
            DeviceTemplateStatement::execute($query, [$actor, $realm]);
            if (DeviceTemplateStatement::fetchColumn($query) === false) {
                throw new InventoryAccessDenied(false);
            }
        }
    }
}
