<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

/** Counts templates with the same principal-scoped exceptions as the grid rows. */
final class PermissionTemplateGrid
{
    /** Read the validated grid request once for either principal type. */
    public static function countFromRequest(bool $group): int
    {
        $rawId = \get_request_var('id');
        $rawFilter = \get_request_var('filter');
        $rawAssociated = \get_request_var('associated');

        return self::count(
            $group,
            is_scalar($rawId) ? (int) $rawId : 0,
            is_scalar($rawFilter) ? (string) $rawFilter : '',
            $rawAssociated !== 'false' && $rawAssociated !== false,
        );
    }

    public static function count(bool $group, int $principal, string $filter, bool $associated): int
    {
        $table = $group ? 'user_auth_group_perms' : 'user_auth_perms';
        $subject = $group ? 'group_id' : 'user_id';
        $parameters = [$principal];
        $conditions = [];
        if ($filter !== '') {
            $conditions[] = 'gt.name LIKE ?';
            $parameters[] = '%' . $filter . '%';
        }
        if ($associated) {
            $conditions[] = "(grants.type = 4 AND grants.$subject = ?)";
            $parameters[] = $principal;
        }
        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $count = \db_fetch_cell_prepared(
            "SELECT COUNT(DISTINCT gt.id)
            FROM graph_templates AS gt
            LEFT JOIN $table AS grants ON gt.id = grants.item_id
                AND grants.type = 4 AND grants.$subject = ?
            $where",
            $parameters,
        );

        return is_scalar($count) ? (int) $count : 0;
    }
}
