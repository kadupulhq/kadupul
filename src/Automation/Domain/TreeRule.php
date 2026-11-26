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
    /** The Duplicate action's default name format; every <rule_name> stands for the original's name. */
    public const string NAME_FORMAT = '<rule_name> (1)';
    /** The legacy field accepted 255 characters; the name it produces is checked against the column. */
    public const int NAME_FORMAT_LENGTH = 255;

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

    /**
     * The name a copy of $name gets from $format. The token is the literal
     * <rule_name> in every locale: the legacy default translated it, so a
     * translated console offered a format its own replacement never matched.
     */
    public static function copyName(string $format, string $name): string
    {
        if (!mb_check_encoding($format, 'UTF-8') || mb_strlen($format, 'UTF-8') > self::NAME_FORMAT_LENGTH || preg_match('/[\x00-\x1f\x7f]/', $format) === 1) {
            throw new \InvalidArgumentException('Enter a valid name format.');
        }
        $copy = str_replace('<rule_name>', $name, $format);
        if ($copy === '' || mb_strlen($copy, 'UTF-8') > TreeRuleChange::NAME_LENGTH) {
            throw new \InvalidArgumentException('The name format must give every copy a name of 1 to 255 characters.');
        }
        return $copy;
    }

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
