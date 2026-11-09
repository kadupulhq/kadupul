<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Domain;

/** An automation_tree_rules row as its settings form shows it. */
final readonly class TreeRule
{
    public const array COLUMNS = ['id', 'name', 'tree_id', 'tree_item_id', 'leaf_type', 'host_grouping_type', 'enabled'];

    public function __construct(
        public int $id,
        public string $name,
        public int $treeId,
        public int $treeItemId,
        public int $leafType,
        public int $hostGroupingType,
        public bool $enabled,
        public string $revision,
    ) {}

    /** @param array<string, mixed> $row */
    public static function revisionOf(array $row): string
    {
        $values = [];
        foreach (self::COLUMNS as $column) {
            $value = $row[$column] ?? null;
            $values[] = $value === null ? null : (is_scalar($value) ? (string) $value : '');
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
