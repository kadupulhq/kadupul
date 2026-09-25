<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/**
 * One table as the server lists it: its information_schema.TABLES status and
 * its SHOW COLUMNS and SHOW INDEXES rows, keyed by the names those print.
 * The values are what the server returned, cast to string, because the
 * script compared them loosely and printed them as they came.
 */
final readonly class LiveTable
{
    /**
     * @param list<array{Field: string, Type: string, Null: string, Key: string, Default: ?string, Extra: string}> $columns
     * @param list<array{Table: string, Non_unique: string, Key_name: string, Seq_in_index: string, Column_name: string, Collation: ?string, Cardinality: ?string, Sub_part: ?string, Packed: ?string, Null: string, Index_type: string, Comment: string}> $indexes
     */
    public function __construct(public string $name, public TableStatus $status, public array $columns, public array $indexes) {}

    /**
     * Whether $other lists the same engine, collation, columns and indexes.
     * Row counts and index cardinality move on their own and are ignored.
     */
    public function sameShape(self $other): bool
    {
        $shape = static fn(self $table): array => [
            $table->name, $table->status->engine, $table->status->collation, $table->columns,
            array_map(static fn(array $index): array => array_diff_key($index, ['Cardinality' => true]), $table->indexes),
        ];

        return $shape($this) === $shape($other);
    }

    /** What report_audit_results() called $collation: only these two collations counted as utf8. */
    public function latin(): bool
    {
        return !in_array($this->status->collation, ['utf8mb4_unicode_ci', 'utf8_general_ci'], true);
    }

    /** db_column_exists(): SHOW COLUMNS ... LIKE, so without letter case and with _ and % as wildcards. */
    public function hasColumnLike(string $pattern): bool
    {
        $regex = '/^' . strtr(preg_quote($pattern, '/'), ['%' => '.*', '_' => '.']) . '$/iDs';

        return array_any($this->columns, static fn(array $column): bool => preg_match($regex, $column['Field']) === 1);
    }

    /** db_index_exists(): in_array() over the Key_name values, loosely but with letter case. */
    public function hasIndex(string $key): bool
    {
        return in_array($key, array_column($this->indexes, 'Key_name'), false);
    }

    /** get_sequence_count(): how many SHOW INDEXES rows the index has. */
    public function indexWidth(string $key): int
    {
        return count(array_filter($this->indexes, static fn(array $index): bool => $key == $index['Key_name']));
    }

    /** get_column_sequence_number(): the column's Seq_in_index in the index, or -1. */
    public function indexPosition(string $key, string $column): int|string
    {
        foreach ($this->indexes as $index) {
            if ($index['Key_name'] == $key && $index['Column_name'] == $column) {
                return $index['Seq_in_index'];
            }
        }

        return -1;
    }
}
