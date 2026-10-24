<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

final readonly class GprintPresetFilters
{
    public const array KEYS = ['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'has_graphs'];

    private const array SORTS = ['name', 'gprint_text', 'graphs', 'templates'];

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
        $list = ListFilterQuery::parse($query, self::KEYS, self::SORTS, 'name', ['has_graphs' => $defaultHasGraphs], $defaultRows, false, 'Invalid GPRINT preset filters.');

        return new self($list->filter, $list->page, $list->rows, $list->rowsParam, $list->sortColumn, $list->sortDirection, $list->flags['has_graphs']);
    }

    /** @return array<string, int|string> */
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
