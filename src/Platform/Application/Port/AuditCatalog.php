<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

use Kadupul\Platform\Domain\Schema\LiveTable;
use Kadupul\Platform\Domain\Schema\PluginSchemaChanges;

/**
 * The target's base tables as SchemaAudit::catalog() read them. Only a table
 * this catalog has reaches DDL, by its exact name, letter case included.
 */
final readonly class AuditCatalog
{
    /** @var array<string, LiveTable> */
    private array $tables;

    /** @param list<LiveTable> $tables in the server's order */
    public function __construct(array $tables, public PluginSchemaChanges $plugins)
    {
        $keyed = [];
        foreach ($tables as $table) {
            $keyed[$table->name] = $table;
        }
        $this->tables = $keyed;
    }

    /** @return list<LiveTable> */
    public function tables(): array
    {
        return array_values($this->tables);
    }

    public function table(string $name): ?LiveTable
    {
        return $this->tables[$name] ?? null;
    }
}
