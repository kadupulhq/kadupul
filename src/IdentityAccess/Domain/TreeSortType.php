<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/** The sort methods graph_tree.sort_type stores (include/global_constants.php:17-21) and their English labels. */
final class TreeSortType
{
    private const array LABELS = [
        '0' => 'Inherit Parent Sorting',
        '1' => 'Manual Ordering (No Sorting)',
        '2' => 'Alphabetic Ordering',
        '3' => 'Numeric Ordering',
        '4' => 'Natural Ordering',
    ];

    /** Empty for a value the table does not define, where the original printed a PHP warning and nothing. */
    public static function label(string $sortType): string
    {
        return self::LABELS[$sortType] ?? '';
    }
}
