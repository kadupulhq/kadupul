<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

use Kadupul\Platform\Domain\Schema\ColumnDefinition;

/**
 * The target's own base tables and their columns, as ColumnWidening::catalog()
 * read them. Only names this catalog has may reach DDL, and they must match
 * exactly, letter case included, so no caller can hand over a name it made up.
 */
final readonly class ColumnCatalog
{
    /** @param array<string, list<ColumnDefinition>> $columns keyed by the table name the server returned, in its order */
    public function __construct(private array $columns) {}

    /** @return list<string> in the server's order */
    public function tables(): array
    {
        // A table named "123" comes back from array_keys() as an int.
        return array_map('strval', array_keys($this->columns));
    }

    public function has(string $table): bool
    {
        return array_key_exists($table, $this->columns);
    }

    /** @return list<ColumnDefinition> in ordinal order; [] when the table is not listed */
    public function columns(string $table): array
    {
        return $this->columns[$table] ?? [];
    }
}
