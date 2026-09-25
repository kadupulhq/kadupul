<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/**
 * Reads docs/audit_schema.sql, the mysqldump output audit_database.php piped
 * into the mysql client. Only its one-row INSERTs into table_columns and
 * table_indexes are data; every value is parsed as a string, an integer or
 * NULL, and nothing in the file is ever sent to the server as SQL. Any other
 * INSERT or REPLACE, or a row that does not parse, makes the whole file unusable.
 */
final class AuditSchemaDump
{
    private const string ROW = '/^INSERT INTO `(table_columns|table_indexes)` VALUES \((.*)\);$/D';
    private const string OTHER_WRITE = '/^\s*(?:\/\*!\d*\s*)?(?:INSERT|REPLACE)\b/i';
    private const string VALUE = "/\\G\\s*(?:'((?:[^'\\\\]|\\\\.)*)'|(NULL)|(-?\\d+))\\s*(,|$)/As";
    private const array ESCAPES = ['0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a"];

    /** @throws InvalidAuditSchema */
    public static function parse(string $dump): AuditBaseline
    {
        $columns = [];
        $indexes = [];
        foreach (explode("\n", $dump) as $number => $line) {
            $line = rtrim($line, "\r");
            if (!str_starts_with($line, 'INSERT ')) {
                // Any other way of writing rows (REPLACE, a lowercase or
                // indented INSERT, one inside a /*! */ comment) is refused,
                // not skipped, so the file cannot hold rows this parser misses.
                if (preg_match(self::OTHER_WRITE, $line) === 1) {
                    throw new InvalidAuditSchema($number + 1);
                }
                continue;
            }
            if (preg_match(self::ROW, $line, $match) !== 1) {
                throw new InvalidAuditSchema($number + 1);
            }
            $values = self::values($match[2], $number + 1);
            if ($match[1] === 'table_columns') {
                $columns[] = self::column($values, $number + 1);
            } else {
                $indexes[] = self::index($values, $number + 1);
            }
        }

        return new AuditBaseline($columns, $indexes);
    }

    /** @return list<int|string|null> */
    private static function values(string $tuple, int $line): array
    {
        $values = [];
        $offset = 0;
        while ($offset < strlen($tuple)) {
            if (preg_match(self::VALUE, $tuple, $match, 0, $offset) !== 1) {
                throw new InvalidAuditSchema($line);
            }
            $values[] = match (true) {
                ($match[3] ?? '') !== '' => (int) $match[3],
                ($match[2] ?? '') === 'NULL' => null,
                default => preg_replace_callback('/\\\\(.)/s', static fn(array $escape): string => self::ESCAPES[$escape[1]] ?? $escape[1], $match[1]),
            };
            $offset += strlen($match[0]);
        }

        return $values;
    }

    /** @param list<int|string|null> $values */
    private static function column(array $values, int $line): BaselineColumn
    {
        if (count($values) !== 8 || !is_string($values[0]) || !is_int($values[1]) || !is_string($values[2])
            || array_any(array_slice($values, 3), static fn(mixed $value): bool => is_int($value))) {
            throw new InvalidAuditSchema($line);
        }

        return new BaselineColumn($values[0], $values[1], $values[2], $values[3], $values[4], $values[5], $values[6], $values[7]);
    }

    /** @param list<int|string|null> $values */
    private static function index(array $values, int $line): BaselineIndex
    {
        [$table, $nonUnique, $key, $sequence, $column, $collation, $cardinality, $subPart, $packed, $null, $type, $comment] = count($values) === 12 ? $values : array_fill(0, 12, false);
        if (!is_string($table) || !(is_int($nonUnique) || $nonUnique === null) || !is_string($key) || !is_int($sequence) || !is_string($column)
            || !(is_int($cardinality) || $cardinality === null) || array_any([$collation, $subPart, $packed, $null, $type, $comment], static fn(mixed $value): bool => !is_string($value) && $value !== null)) {
            throw new InvalidAuditSchema($line);
        }

        // idx_sub_part is a varchar column that mysqldump wrote as a quoted number.
        return new BaselineIndex($table, $nonUnique, $key, $sequence, $column, $collation, $cardinality, $subPart, $packed, $null, $type, $comment);
    }
}
