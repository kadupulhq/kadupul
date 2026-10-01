<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Domain;

final readonly class ColorTemplateFilters
{
    private const array SORTS = ['name', 'graphs', 'templates', 'nosort'];

    private function __construct(
        public string $filter,
        public int $page,
        public int $rows,
        public string $rowsParam,
        public string $sortColumn,
        public string $sortDirection,
        public bool $hasGraphs,
    ) {}

    public static function fromQuery(array $query, int $defaultRows, bool $defaultHasGraphs = false): self
    {
        $allowed = ['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'has_graphs'];
        if (array_diff(array_keys($query), $allowed) !== [] || array_filter($query, static fn($value): bool => !is_string($value)) !== []) {
            throw new \InvalidArgumentException('Invalid color template filters.');
        }
        $filter = $query['filter'] ?? '';
        $page = self::integer($query['page'] ?? '1', 1, 1000000);
        $rowsParam = $query['rows'] ?? '-1';
        $rows = $rowsParam === '-1' ? $defaultRows : self::integer($rowsParam, 1, 5000);
        $sort = $query['sort_column'] ?? 'name';
        $direction = strtoupper($query['sort_direction'] ?? 'ASC');
        $hasGraphs = $query['has_graphs'] ?? ($defaultHasGraphs ? 'true' : 'false');
        if (preg_match('//u', $filter) !== 1 || mb_strlen($filter, 'UTF-8') > 200 || str_contains($filter, "\0")
            || !in_array($sort, self::SORTS, true) || !in_array($direction, ['ASC', 'DESC'], true)
            || !in_array($hasGraphs, ['true', 'false'], true) || $rows < 1) {
            throw new \InvalidArgumentException('Invalid color template filters.');
        }
        return new self($filter, $page, $rows, $rowsParam, $sort, $direction, $hasGraphs === 'true');
    }

    private static function integer(string $value, int $min, int $max): int
    {
        if (!preg_match('/\A[0-9]{1,7}\z/D', $value)) {
            throw new \InvalidArgumentException('Invalid color template filters.');
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            throw new \InvalidArgumentException('Invalid color template filters.');
        }
        return $number;
    }

    public function query(): array
    {
        return ['filter' => $this->filter, 'rows' => $this->rowsParam, 'page' => $this->page,
            'sort_column' => $this->sortColumn, 'sort_direction' => $this->sortDirection,
            'has_graphs' => $this->hasGraphs ? 'true' : 'false'];
    }
}
