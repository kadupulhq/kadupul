<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/**
 * report_audit_results()'s index passes and make_index_alter() (lines
 * 558-674 and 795-896 of cli/audit_database.php on origin/main), kept
 * expression for expression, loose comparisons included, like ColumnDrift.
 * An index can be rebuilt twice in one ALTER, which the server refuses, as it
 * refused the original's; that is kept too. Never declare strict_types here.
 */
final class IndexDrift
{
    private const array ATTRIBUTES = ['idx_non_unique' => 'Non_unique', 'idx_key_name' => 'Key_name', 'idx_seq_in_index' => 'Seq_in_index',
        'idx_column_name' => 'Column_name', 'idx_packed' => 'Packed', 'idx_comment' => 'Comment'];

    /**
     * @param bool $output true for --report, which prints what it finds
     * @return array{lines: list<string>, errors: int, clauses: list<AlterClause>}
     */
    public static function audit(LiveTable $table, AuditBaseline $baseline, bool $output): array
    {
        $lines = [];
        $errors = 0;
        $clauses = [];
        $added = [];
        $dropped = [];
        foreach ($table->indexes as $i) {
            $parts = $baseline->index($i['Table'], $i['Key_name']);
            $dbc = array_find($parts, static fn(BaselineIndex $part): bool => $part->seqInIndex == $i['Seq_in_index'] && strcasecmp($part->columnName, $i['Column_name']) === 0)?->row();
            if ($dbc === null) {
                // A known key with other columns waits for the second pass;
                // primary keys are only ever rebuilt there.
                if ($parts === [] && array_search($i['Key_name'], $dropped) === false && $i['Key_name'] != 'PRIMARY') {
                    if ($output) {
                        $lines[] = "WARNING Index: '" . $i['Key_name'] . "', does not exist in default Kadupul.  Dropping.";
                    }
                    $clauses[] = new DropIndex($i['Key_name']);
                    $dropped[] = $i['Key_name'];
                    $errors++;
                }
                continue;
            }
            foreach (self::ATTRIBUTES as $dbidx => $idx) {
                if ($i[$idx] != $dbc[$dbidx] && $i['Key_name'] != 'PRIMARY' && array_search($i['Key_name'], $added) === false) {
                    if ($output) {
                        $lines[] = "ERROR Index: '" . $i['Key_name'] . "', Attribute '" . $idx . "' invalid. Should be: '" . $dbc[$dbidx] . "', Is: '" . $i[$idx] . "'";
                    }
                    array_push($clauses, ...self::rebuild($table, $baseline, $i['Key_name']));
                    $added[] = $i['Key_name'];
                    $errors++;
                }
            }
        }
        foreach ($baseline->indexes($table->name) as $index) {
            $key = $index->keyName;
            if (!$table->hasIndex($key)) {
                if (array_search($key, $added) === false) {
                    if ($output) {
                        $lines[] = "ERROR Index: '" . $key . "', is missing from '" . $table->name . "'";
                    }
                    array_push($clauses, ...self::rebuild($table, $baseline, $key));
                    $added[] = $key;
                    $errors++;
                }
                continue;
            }
            $proposed = count($baseline->index($table->name, $key));
            $current = $table->indexWidth($key);
            $position = $table->indexPosition($key, $index->columnName);
            if (($current != $proposed || $position != $index->seqInIndex) && array_search($key, $dropped) === false) {
                if ($output && $current != $proposed) {
                    $lines[] = "WARNING Index: '" . $key . "', has differing number of columns.  Dropping.";
                }
                if ($output && $position != $index->seqInIndex) {
                    $lines[] = "WARNING Index: '" . $key . "', has resequenced columns.  Dropping.";
                }
                array_push($clauses, ...self::rebuild($table, $baseline, $key));
                $added[] = $key;
                $dropped[] = $key;
                $errors++;
            }
        }

        return ['lines' => $lines, 'errors' => $errors, 'clauses' => $clauses];
    }

    /**
     * make_index_alter(): nothing when the audit schema has no such index,
     * which also drops the DROP it began with.
     *
     * @return list<AlterClause>
     */
    private static function rebuild(LiveTable $table, AuditBaseline $baseline, string $key): array
    {
        $parts = $baseline->index($table->name, $key);
        if ($parts === []) {
            return [];
        }
        $drops = [];
        $width = $table->indexWidth($key);
        if (($width != count($parts) && $width > 0) || $table->hasIndex($key)) {
            $drops[] = $key == 'PRIMARY' ? null : $key;
        }
        $primary = $parts[0]->keyName == 'PRIMARY';
        if ($primary && !in_array(null, $drops, true)) {
            $drops[] = null;
        }
        $using = '';
        foreach ($parts as $part) {
            if ($using == '' && isset($part->indexType) && $part->indexType != '') {
                $using = $part->indexType;
            }
        }
        $text = '';
        foreach ($drops as $drop) {
            $text .= ($drop === null ? 'DROP PRIMARY KEY' : 'DROP INDEX `' . $drop . '`') . ",\n   ";
        }
        $unique = $parts[0]->nonUnique != 1;
        $text .= match (true) {
            $primary => 'ADD PRIMARY KEY (',
            $unique => 'ADD UNIQUE INDEX `' . $key . '` (',
            default => 'ADD INDEX `' . $key . '` (',
        };
        $columns = array_map(static fn(BaselineIndex $part): string => $part->columnName, $parts);
        $text .= '`' . implode('`,`', $columns) . '`';
        // Without a USING the original never closed the column list.
        if ($using != '') {
            $text .= ') USING ' . $using;
        }
        $algorithm = IndexAlgorithm::tryFrom(strtoupper((string) $using));
        // The loose checks above can match a live key that is spelled
        // differently ("1e1" == "10"); a DROP must name one exactly.
        $live = array_column($table->indexes, 'Key_name');
        $named = BaselineName::valid($key) && array_all($columns, BaselineName::valid(...))
            && array_all($drops, static fn(?string $drop): bool => $drop === null || in_array($drop, $live, true));

        return [$algorithm === null || !$named ? new UnbuildableClause($text) : new RebuildIndex($drops, $primary, $unique, $key, $columns, $algorithm, $text)];
    }
}
