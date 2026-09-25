<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/**
 * The plugin_db_changes rows the audit consults. The script matched them with
 * "=" under the table's case-insensitive collation, so names fold case here.
 */
final readonly class PluginSchemaChanges
{
    /**
     * @param list<string> $tables tables a plugin recorded with method 'create'
     * @param list<array{0: string, 1: string}> $columns table and column a plugin recorded with method 'addcolumn'
     */
    public function __construct(private array $tables, private array $columns) {}

    public static function none(): self
    {
        return new self([], []);
    }

    public function createdTable(string $table): bool
    {
        return array_any($this->tables, static fn(string $name): bool => strcasecmp($name, $table) === 0);
    }

    public function addedColumn(string $table, string $column): bool
    {
        return array_any($this->columns, static fn(array $pair): bool => strcasecmp($pair[0], $table) === 0 && strcasecmp($pair[1], $column) === 0);
    }
}
