<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

final readonly class PaletteColorFilters
{
    private const array SORTS = ['name', 'hex', 'read_only', 'graphs', 'templates'];

    private function __construct(
        public string $filter,
        public int $page,
        public int $rows,
        public string $rowsParam,
        public string $sortColumn,
        public string $sortDirection,
        public bool $hasGraphs,
        public bool $named,
    ) {}

    public static function fromQuery(array $query, int $defaultRows, bool $defaultHasGraphs = false): self
    {
        $list = ListFilterQuery::parse($query, ['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'has_graphs', 'named'], self::SORTS, 'name', ['has_graphs' => $defaultHasGraphs, 'named' => true], $defaultRows, false, 'Invalid color filters.');
        return new self($list->filter, $list->page, $list->rows, $list->rowsParam, $list->sortColumn, $list->sortDirection, $list->flags['has_graphs'], $list->flags['named']);
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
            'named' => $this->named ? 'true' : 'false',
        ];
    }
}
