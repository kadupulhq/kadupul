<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/**
 * The query grammar the Graphing list pages share. Each list supplies its own
 * keys, sort columns, flags and error message, so a rejected query reads the
 * same as it did when every list parsed it alone.
 */
final readonly class ListFilterQuery
{
    /** @param array<string, bool> $flags */
    private function __construct(
        public string $filter,
        public int $page,
        public int $rows,
        public string $rowsParam,
        public string $sortColumn,
        public string $sortDirection,
        public array $flags,
    ) {}

    /**
     * @param array<array-key, mixed> $query
     * @param list<string> $keys
     * @param list<string> $sorts
     * @param array<string, bool> $flags each true/false flag key and its default
     * @param bool $countCharacters limit the filter to 200 UTF-8 characters instead of 200 bytes
     */
    public static function parse(array $query, array $keys, array $sorts, string $defaultSort, array $flags, int $defaultRows, bool $countCharacters, string $error): self
    {
        if (array_diff(array_keys($query), $keys) !== []) {
            throw new \InvalidArgumentException($error);
        }
        foreach ($query as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException($error);
            }
        }
        $filter = $query['filter'] ?? '';
        $page = self::integer($query['page'] ?? '1', 1, 1000000, $error);
        $rowsText = $query['rows'] ?? '-1';
        $rows = $rowsText === '-1' ? $defaultRows : self::integer($rowsText, 1, 5000, $error);
        $sort = $query['sort_column'] ?? $defaultSort;
        $direction = strtoupper($query['sort_direction'] ?? 'ASC');
        $values = [];
        foreach ($flags as $key => $default) {
            $values[$key] = $query[$key] ?? ($default ? 'true' : 'false');
        }
        $length = $countCharacters ? mb_strlen($filter, 'UTF-8') : strlen($filter);
        if ($length > 200 || preg_match('//u', $filter) !== 1 || str_contains($filter, "\0")
            || !in_array($sort, $sorts, true) || !in_array($direction, ['ASC', 'DESC'], true)
            || array_any($values, static fn(string $value): bool => !in_array($value, ['true', 'false'], true)) || $rows < 1) {
            throw new \InvalidArgumentException($error);
        }

        return new self($filter, $page, $rows, $rowsText, $sort, $direction, array_map(static fn(string $value): bool => $value === 'true', $values));
    }

    private static function integer(string $value, int $minimum, int $maximum, string $error): int
    {
        if (preg_match('/\A[0-9]{1,7}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException($error);
        }
        $parsed = (int) $value;
        if ($parsed < $minimum || $parsed > $maximum) {
            throw new \InvalidArgumentException($error);
        }

        return $parsed;
    }
}
