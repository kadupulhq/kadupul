<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** Which columns fix_mediumint.php would widen in a schema, decided before any statement runs. */
final class IdColumnPlan
{
    /**
     * An entry with no verdict is a statement to plan or send for its columns.
     *
     * @param array<string, list<ColumnDefinition>> $schema base tables in the server's order
     * @return list<array{table: string, column: ?string, verdict: ?ColumnVerdict, columns: list<ColumnDefinition>}> in the order fix_mediumint.php printed its lines
     */
    public static function decide(array $schema): array
    {
        $shared = IdColumns::SHARED;
        $decisions = [];
        foreach (IdColumns::KNOWN as $table => $wanted) {
            array_push($decisions, ...self::named($schema[$table] ?? [], $table, $wanted, $shared));
        }
        foreach ($schema as $table => $columns) {
            // A table named "123" is an int key.
            $table = (string) $table;
            if (!array_key_exists($table, IdColumns::KNOWN)) {
                array_push($decisions, ...self::other($columns, $table, $shared));
            }
        }

        return $decisions;
    }

    /**
     * @param list<ColumnDefinition> $columns
     * @param list<string> $wanted
     * @param list<string> $shared grows by each widened column other than id or an auto-increment
     * @return list<array{table: string, column: ?string, verdict: ?ColumnVerdict, columns: list<ColumnDefinition>}>
     */
    private static function named(array $columns, string $table, array $wanted, array &$shared): array
    {
        $found = [];
        foreach ($columns as $column) {
            $found[$column->name] = $column;
        }
        $decisions = [];
        $narrow = [];
        foreach ($wanted as $name) {
            $column = $found[$name] ?? null;
            if ($column === null) {
                $decisions[] = self::decision($table, $name, ColumnVerdict::MissingColumn);
            } elseif (!$column->needsWidening()) {
                $decisions[] = self::decision($table, $name, ColumnVerdict::AlreadyWide);
            } elseif (!$column->changeable()) {
                $decisions[] = self::decision($table, $name, ColumnVerdict::Skipped);
            } else {
                if (!$column->change()->autoIncrement && $name !== 'id') {
                    $shared[] = $name;
                }
                $narrow[] = $column;
            }
        }
        // The original announced a named table's statement after its columns.
        if ($narrow !== []) {
            $decisions[] = self::decision($table, null, null, $narrow);
        }

        return $decisions;
    }

    /**
     * @param list<ColumnDefinition> $columns
     * @param list<string> $shared
     * @return list<array{table: string, column: ?string, verdict: ?ColumnVerdict, columns: list<ColumnDefinition>}>
     */
    private static function other(array $columns, string $table, array $shared): array
    {
        $decisions = [];
        $narrow = [];
        $first = 0;
        foreach ($columns as $column) {
            if (!in_array($column->name, $shared, true)) {
                continue;
            }
            if (!$column->needsWidening() || !$column->changeable()) {
                $decisions[] = self::decision($table, $column->name, $column->needsWidening() ? ColumnVerdict::Skipped : ColumnVerdict::AlreadyWide);
                continue;
            }
            if ($narrow === []) {
                $first = count($decisions);
            }
            $narrow[] = $column;
        }
        // One statement per table, as install/upgrades/1_2_17.php:159-192 does;
        // the cli/ copy appended these to the previous table's statement. It is
        // reported where the original printed its first "Updating Table" line.
        if ($narrow !== []) {
            array_splice($decisions, $first, 0, [self::decision($table, null, null, $narrow)]);
        }

        return $decisions;
    }

    /**
     * @param list<ColumnDefinition> $columns
     * @return array{table: string, column: ?string, verdict: ?ColumnVerdict, columns: list<ColumnDefinition>}
     */
    private static function decision(string $table, ?string $column, ?ColumnVerdict $verdict, array $columns = []): array
    {
        return ['table' => $table, 'column' => $column, 'verdict' => $verdict, 'columns' => $columns];
    }
}
