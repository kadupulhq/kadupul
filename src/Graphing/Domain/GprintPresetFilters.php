<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

final readonly class GprintPresetFilters
{
    private const array SORTS = ['name', 'gprint_text', 'nosort', 'graphs', 'templates'];

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
        if (array_diff(array_keys($query), $allowed) !== []) {
            throw new \InvalidArgumentException('Invalid GPRINT preset filters.');
        }
        foreach ($query as $key => $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Invalid GPRINT preset filters.');
            }
        }
        $filter = $query['filter'] ?? '';
        $page = self::integer($query['page'] ?? '1', 1, 1000000);
        $rowsText = $query['rows'] ?? '-1';
        $rows = $rowsText === '-1' ? $defaultRows : self::integer($rowsText, 1, 5000);
        $sort = $query['sort_column'] ?? 'name';
        $direction = strtoupper($query['sort_direction'] ?? 'ASC');
        $hasGraphs = $query['has_graphs'] ?? ($defaultHasGraphs ? 'true' : 'false');
        if (mb_strlen($filter, 'UTF-8') > 200 || preg_match('//u', $filter) !== 1 || str_contains($filter, "\0")
            || !in_array($sort, self::SORTS, true) || !in_array($direction, ['ASC', 'DESC'], true)
            || !in_array($hasGraphs, ['true', 'false'], true) || $rows < 1) {
            throw new \InvalidArgumentException('Invalid GPRINT preset filters.');
        }
        return new self($filter, $page, $rows, $rowsText, $sort, $direction, $hasGraphs === 'true');
    }

    private static function integer(string $value, int $minimum, int $maximum): int
    {
        if (!preg_match('/\A[0-9]{1,7}\z/D', $value)) {
            throw new \InvalidArgumentException('Invalid GPRINT preset filters.');
        }
        $parsed = (int) $value;
        if ($parsed < $minimum || $parsed > $maximum) {
            throw new \InvalidArgumentException('Invalid GPRINT preset filters.');
        }
        return $parsed;
    }

    public function query(): array
    {
        return [
            'filter' => $this->filter,
            'rows' => $this->rowsParam,
            'page' => $this->page,
            'sort_column' => $this->sortColumn,
            'sort_direction' => $this->sortDirection,
            'has_graphs' => $this->hasGraphs ? 'true' : 'false',
        ];
    }
}
