<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

/** The sort methods, node kinds and device grouping styles add_tree.php names, with the numbers graph_tree and graph_tree_items store. */
final class TreeLabels
{
    /** @var array<string, int> --sort-method => graph_tree.sort_type (include/global_constants.php) */
    public const array SORT_METHODS = ['manual' => 1, 'alpha' => 2, 'natural' => 4, 'numeric' => 3];

    /** @var array<string, int> --node-type => the TREE_ITEM_TYPE_* constant */
    public const array NODE_TYPES = ['header' => 1, 'graph' => 2, 'host' => 3, 'site' => 4];

    private const array SORT_LABELS = [0 => 'Inherit Parent Sorting', 1 => 'Manual Ordering (No Sorting)', 2 => 'Alphabetic Ordering', 3 => 'Numeric Ordering', 4 => 'Natural Ordering'];

    private const array GROUPING_LABELS = [1 => 'Graph Template', 2 => 'Data Query Index'];

    /** Empty for a value the table does not define, where the original printed a PHP warning and nothing. */
    public static function sort(int $sortType): string
    {
        return self::SORT_LABELS[$sortType] ?? '';
    }

    /** Label of a device grouping style; empty for a value the table does not define. */
    public static function grouping(int $groupingType): string
    {
        return self::GROUPING_LABELS[$groupingType] ?? '';
    }

    /** Label of a node kind; any kind other than graph and host reads as Header. */
    public static function kind(string $kind): string
    {
        return match ($kind) {
            'graph' => 'Graph',
            'host' => 'Device',
            default => 'Header',
        };
    }
}
