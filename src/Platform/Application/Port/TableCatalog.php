<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

use Kadupul\Platform\Domain\Schema\TableStatus;

/**
 * The target's own base tables, as TableConversion::tableStatuses() read them.
 * Only a name this catalog has may reach DDL, and it must match exactly,
 * letter case included, so no caller can hand over a list it made up.
 */
final readonly class TableCatalog
{
    /** @param array<string, TableStatus> $statuses keyed by the name the server returned */
    public function __construct(private array $statuses) {}

    /** @return list<string> in the order the catalog was read */
    public function names(): array
    {
        // A table named "123" comes back from array_keys() as an int.
        return array_map('strval', array_keys($this->statuses));
    }

    public function has(string $table): bool
    {
        return array_key_exists($table, $this->statuses);
    }

    public function status(string $table): ?TableStatus
    {
        return $this->statuses[$table] ?? null;
    }
}
