<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/**
 * report_audit_results()'s column passes and make_column_*() (lines 440-556
 * and 705-793 of cli/audit_database.php on origin/main), kept expression for
 * expression. The loose comparisons, the "! $x ?: ..." rewrite that turns an
 * empty value into true, and the in-place changes to the baseline row decide
 * which columns --repair changes and what the MODIFY says, so none of them is
 * tidied. This file relies on PHP's coercions exactly as the script did,
 * which is why it must never declare strict_types.
 */
final class ColumnDrift
{
    private const array ATTRIBUTES = ['table_type' => 'Type', 'table_null' => 'Null', 'table_key' => 'Key', 'table_default' => 'Default', 'table_extra' => 'Extra'];

    /**
     * @param bool $output true for --report, which prints what it finds
     * @return array{lines: list<string>, errors: int, warnings: int, clauses: list<AlterClause>, widened: list<WidenedColumn>}
     */
    public static function audit(LiveTable $table, AuditBaseline $baseline, PluginSchemaChanges $plugins, bool $output): array
    {
        $lines = [];
        $errors = 0;
        $warnings = 0;
        $clauses = [];
        $widened = [];
        $altered = [];
        $added = [];
        $latin = $table->latin();
        foreach ($table->columns as $c) {
            $found = $baseline->column($table->name, $c['Field']);
            if ($found === null) {
                if (!$plugins->addedColumn($table->name, $c['Field'])) {
                    if ($output) {
                        $lines[] = "WARNING Col: '" . $c['Field'] . "', does not exist in default Kadupul.  Plugin possible";
                    }
                    $warnings++;
                }
                continue;
            }
            $dbc = $found->row();
            // The one departure from the original: a column wider than the
            // audit schema lists is never modified, since the MODIFY would
            // narrow it back.
            $wider = self::widened($c, $dbc);
            $differs = false;
            foreach (self::ATTRIBUTES as $dbcol => $col) {
                if ($col == 'Type' && $dbc[$dbcol] == 'text') {
                    if ($latin) {
                        $dbc[$dbcol] = 'mediumtext';
                    }
                }
                $c[$col] = !$c[$col] ?: str_replace('current_timestamp()', 'CURRENT_TIMESTAMP', $c[$col]);
                $dbc[$dbcol] = !$dbc[$dbcol] ?: str_replace('current_timestamp()', 'CURRENT_TIMESTAMP', $dbc[$dbcol]);
                if (strpos($dbc[$dbcol], 'int(') !== false) {
                    $parts = explode('(', $dbc[$dbcol]);
                    $adbccol = $parts[0];
                    $parts = explode(' ', $parts[1], 2);
                    if (isset($parts[1])) {
                        $adbccol .= ' ' . $parts[1];
                    }
                    $adbccol = trim($adbccol);
                } else {
                    $adbccol = $dbc[$dbcol];
                }
                $c[$col] = trim(str_replace('DEFAULT_GENERATED', '', $c[$col]));
                if (($c[$col] != $dbc[$dbcol] && $c[$col] != $adbccol) && $c[$col] != 'mediumtext') {
                    if ($wider !== null) {
                        $differs = true;

                        continue;
                    }
                    if ($output && $col != 'Key') {
                        if ($col == 'Extra' && $dbc[$dbcol] == '1' && $c[$col] == '') {
                            // The original skipped the rest of this attribute here, the
                            // alter included; only --report reaches this branch.
                            continue;
                        }
                        $lines[] = "ERROR Col: '" . $c['Field'] . "', Attribute '" . $col . "' invalid. Should be: '" . $dbc[$dbcol] . "', Is: '" . $c[$col] . "'";
                    }
                    if (array_search($dbc['table_field'], $altered) === false) {
                        $clauses[] = self::modify($dbc, $c['Field']);
                        $altered[] = $dbc['table_field'];
                        $errors++;
                    }
                }
            }
            if ($differs && $wider !== null) {
                if ($output) {
                    $lines[] = $wider->line();
                }
                $widened[] = $wider;
                $warnings++;
            }
        }
        foreach ($baseline->columns($table->name) as $column) {
            if (!$table->hasColumnLike($column->field) && array_search($column->field, $added) === false) {
                if ($output) {
                    $lines[] = "WARNING Col: '" . $column->field . "' is missing from '" . $table->name . "'";
                }
                $clauses[] = self::add($baseline, $table->name, $column->row());
                $added[] = $column->field;
                $errors++;
            }
        }

        return ['lines' => $lines, 'errors' => $errors, 'warnings' => $warnings, 'clauses' => $clauses, 'widened' => $widened];
    }

    /**
     * The column, when the audit schema lists it in a type narrower than the
     * live one. Types outside ColumnType's grammar are never narrower.
     *
     * @param array{Field: string, Type: string} $live the column as SHOW COLUMNS printed it
     * @param array<string, mixed> $dbc the baseline row as loaded
     */
    private static function widened(array $live, array $dbc): ?WidenedColumn
    {
        $type = ColumnType::parse((string) $live['Type']);
        $listed = ColumnType::parse((string) $dbc['table_type']);
        if ($type === null || $listed === null || !$listed->narrows($type)) {
            return null;
        }

        return new WidenedColumn((string) $live['Field'], (string) $live['Type'], (string) $dbc['table_type']);
    }

    /**
     * @param array<string, mixed> $dbc the baseline row as the attribute loop left it
     * @param string $field the column as the server lists it; the baseline matched it without letter case
     */
    private static function modify(array $dbc, string $field): AlterClause
    {
        $head = 'MODIFY COLUMN `' . $dbc['table_field'] . '` ' . $dbc['table_type'] . ($dbc['table_null'] == 'NO' ? ' NOT NULL' : '');
        [$props, $default, $now, $extra] = self::props($dbc);
        // The typed clause names the live column exactly, so the adapter can
        // hold it to the catalog; the original's text keeps the baseline's case.
        $spec = self::spec($field, $dbc, $default, $now, $extra);

        return $spec === null ? new UnbuildableClause($head . $props) : new ModifyColumn($spec, $head . $props);
    }

    /** @param array<string, mixed> $dbc the baseline row as loaded */
    private static function add(AuditBaseline $baseline, string $table, array $dbc): AlterClause
    {
        $after = self::previous($baseline, $table, $dbc['table_field']);
        $position = $after === 'first' ? 'first' : 'AFTER `' . $after . '`';
        $head = 'ADD COLUMN `' . $dbc['table_field'] . '` ' . $dbc['table_type'] . ($dbc['table_null'] == 'NO' ? ' NOT NULL' : '');
        [$props, $default, $now, $extra] = self::props($dbc);
        $legacy = $head . $props . ' ' . $position;
        $spec = self::spec((string) $dbc['table_field'], $dbc, $default, $now, $extra);
        // get_previous_column() returned nothing for a column without a
        // sequence, which made "AFTER ``".
        if ($spec === null || ($after !== 'first' && !BaselineName::valid($after))) {
            return new UnbuildableClause($legacy);
        }

        return new AddColumn($spec, $after === 'first' ? null : $after, $legacy);
    }

    /** get_previous_column(): 'first', the field one sequence earlier, or null when there is none. */
    private static function previous(AuditBaseline $baseline, string $table, string $field): ?string
    {
        $sequence = $baseline->column($table, $field)?->sequence;
        if (empty($sequence)) {
            return null;
        }
        if ($sequence == 1) {
            return 'first';
        }

        return array_find($baseline->columns($table), static fn(BaselineColumn $column): bool => $column->sequence == $sequence - 1)?->field;
    }

    /**
     * make_column_props(): the text, and the same decisions as typed parts.
     *
     * @param array<string, mixed> $dbc
     * @return array{0: string, 1: ?string, 2: bool, 3: string}
     */
    private static function props(array $dbc): array
    {
        $text = '';
        $default = null;
        $now = false;
        if (isset($dbc['table_default'])) {
            $dbc['table_default'] = str_replace('current_timestamp()', 'CURRENT_TIMESTAMP', $dbc['table_default']);
        }
        if (isset($dbc['table_extra'])) {
            $dbc['table_extra'] = str_replace('current_timestamp()', 'CURRENT_TIMESTAMP', $dbc['table_extra']);
            $dbc['table_extra'] = trim(str_replace('DEFAULT_GENERATED', '', $dbc['table_extra']));
        }
        if ($dbc['table_null'] == 'YES') {
            if ($dbc['table_default'] == 'NULL' || $dbc['table_default'] === null || $dbc['table_default'] === '') {
                // No default clause.
            } elseif ($dbc['table_default'] != 'CURRENT_TIMESTAMP') {
                $text .= ' DEFAULT "' . $dbc['table_default'] . '"';
                $default = (string) $dbc['table_default'];
            } else {
                $text .= ' DEFAULT CURRENT_TIMESTAMP';
                $now = true;
            }
        } elseif ($dbc['table_default'] !== 'NULL' && $dbc['table_default'] !== null) {
            if ($dbc['table_default'] == 'CURRENT_TIMESTAMP') {
                $text .= ' DEFAULT CURRENT_TIMESTAMP';
                $now = true;
            } elseif ($dbc['table_extra'] != 'auto_increment') {
                if (strpos($dbc['table_type'], 'int(') !== false && $dbc['table_default'] == '') {
                    $text .= ' DEFAULT "0"';
                    $default = '0';
                } else {
                    $text .= " DEFAULT '" . $dbc['table_default'] . "'";
                    $default = (string) $dbc['table_default'];
                }
            }
        }
        $extra = (string) $dbc['table_extra'];
        if ($dbc['table_extra'] != '') {
            $text .= ' ' . $dbc['table_extra'];
        }

        return [$text, $default, $now, $extra];
    }

    /** @param array<string, mixed> $dbc */
    private static function spec(string $name, array $dbc, ?string $default, bool $now, string $extra): ?ColumnSpec
    {
        $type = ColumnType::parse((string) $dbc['table_type']);
        $parsed = ColumnExtra::fromLegacy($extra);
        if ($type === null || $parsed === null || !BaselineName::valid($name)) {
            return null;
        }

        return new ColumnSpec($name, $type, $dbc['table_null'] == 'NO', $default, $now, $parsed);
    }
}
