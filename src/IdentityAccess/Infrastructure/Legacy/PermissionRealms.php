<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

/** A fresh principal-scoped realm selection for one permission form. */
final class PermissionRealms
{
    /** @return array<int|string, true> */
    public static function selected(bool $group, int $principal): array
    {
        $sql = $group
            ? 'SELECT realm_id FROM user_auth_group_realm WHERE group_id = ?'
            : 'SELECT realm_id FROM user_auth_realm WHERE user_id = ?';
        $rows = \db_fetch_assoc_prepared($sql, [$principal]);
        $selected = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $realmId = $row['realm_id'] ?? null;
            // The prior individual lookup treats realm zero as unchecked.
            if ((is_int($realmId) || is_string($realmId)) && (bool) $realmId) {
                $selected[$realmId] = true;
            }
        }

        return $selected;
    }
}
