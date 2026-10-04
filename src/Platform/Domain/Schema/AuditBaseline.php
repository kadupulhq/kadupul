<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/**
 * The audit schema: what table_columns and table_indexes held after
 * audit_database.php loaded docs/audit_schema.sql. The script looked rows up
 * with "WHERE table_name = ?" under utf8mb4_unicode_ci, so names match here
 * without letter case. mysqldump wrote the rows in primary key order, which
 * is the order a SELECT without ORDER BY returned them, so file order is kept.
 */
final readonly class AuditBaseline
{
    /** @var array<string, list<BaselineColumn>> */
    private array $columns;
    /** @var array<string, list<BaselineIndex>> */
    private array $indexes;

    /**
     * @param list<BaselineColumn> $columnRows in file order
     * @param list<BaselineIndex> $indexRows in file order
     */
    public function __construct(public array $columnRows, public array $indexRows)
    {
        $columns = [];
        foreach ($columnRows as $column) {
            $columns[strtolower($column->table)][] = $column;
        }
        $indexes = [];
        foreach ($indexRows as $index) {
            $indexes[strtolower($index->table)][] = $index;
        }
        $this->columns = $columns;
        $this->indexes = $indexes;
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public function hasTable(string $table): bool
    {
        return isset($this->columns[strtolower($table)]);
    }

    /** @return list<BaselineColumn> */
    public function columns(string $table): array
    {
        return $this->columns[strtolower($table)] ?? [];
    }

    public function column(string $table, string $field): ?BaselineColumn
    {
        return array_find($this->columns($table), static fn(BaselineColumn $column): bool => strcasecmp($column->field, $field) === 0);
    }

    /** @return list<BaselineIndex> */
    public function indexes(string $table): array
    {
        return $this->indexes[strtolower($table)] ?? [];
    }

    /** @return list<BaselineIndex> one index's columns, by idx_seq_in_index as make_index_alter() ordered them */
    public function index(string $table, string $key): array
    {
        $parts = array_values(array_filter($this->indexes($table), static fn(BaselineIndex $index): bool => strcasecmp($index->keyName, $key) === 0));
        usort($parts, static fn(BaselineIndex $a, BaselineIndex $b): int => $a->seqInIndex <=> $b->seqInIndex);

        return $parts;
    }
}
