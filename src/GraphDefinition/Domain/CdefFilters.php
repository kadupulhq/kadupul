<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

final readonly class CdefFilters
{
    public const array KEYS = ['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'has_graphs'];

    private const array SORTS = ['name', 'graphs', 'templates'];

    private function __construct(
        public string $filter,
        public int $page,
        public int $rows,
        public string $rowsParam,
        public string $sortColumn,
        public string $sortDirection,
        public bool $hasGraphs,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query, int $defaultRows, bool $defaultHasGraphs = false): self
    {
        if (array_diff(array_keys($query), self::KEYS) !== []) {
            throw new \InvalidArgumentException('Invalid CDEF filters.');
        }
        foreach ($query as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Invalid CDEF filters.');
            }
        }
        $filter = $query['filter'] ?? '';
        $page = self::integer($query['page'] ?? '1', 1, 1000000);
        $rowsText = $query['rows'] ?? '-1';
        $rows = $rowsText === '-1' ? $defaultRows : self::integer($rowsText, 1, 5000);
        $sort = $query['sort_column'] ?? 'name';
        $direction = strtoupper($query['sort_direction'] ?? 'ASC');
        $hasGraphs = $query['has_graphs'] ?? ($defaultHasGraphs ? 'true' : 'false');
        if (strlen($filter) > 200 || preg_match('//u', $filter) !== 1 || str_contains($filter, "\0")
            || !in_array($sort, self::SORTS, true) || !in_array($direction, ['ASC', 'DESC'], true)
            || !in_array($hasGraphs, ['true', 'false'], true) || $rows < 1) {
            throw new \InvalidArgumentException('Invalid CDEF filters.');
        }

        return new self($filter, $page, $rows, $rowsText, $sort, $direction, $hasGraphs === 'true');
    }

    private static function integer(string $value, int $minimum, int $maximum): int
    {
        if (preg_match('/\A[0-9]{1,7}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid CDEF filters.');
        }
        $parsed = (int) $value;
        if ($parsed < $minimum || $parsed > $maximum) {
            throw new \InvalidArgumentException('Invalid CDEF filters.');
        }

        return $parsed;
    }

    /** @return array<string, string> */
    public function query(): array
    {
        return [
            'filter' => $this->filter,
            'rows' => $this->rowsParam,
            'page' => (string) $this->page,
            'sort_column' => $this->sortColumn,
            'sort_direction' => $this->sortDirection,
            'has_graphs' => $this->hasGraphs ? 'true' : 'false',
        ];
    }
}
