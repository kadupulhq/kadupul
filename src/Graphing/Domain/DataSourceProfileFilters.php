<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

final readonly class DataSourceProfileFilters
{
    public const array KEYS = ['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'has_data'];

    private const array SORTS = ['name', 'step', 'heartbeat', 'data_sources', 'templates'];

    private function __construct(
        public string $filter,
        public int $page,
        public int $rows,
        public string $rowsParam,
        public string $sortColumn,
        public string $sortDirection,
        public bool $hasData,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query, int $defaultRows, bool $defaultHasData = false): self
    {
        // The legacy list sorted by polling interval until a column was chosen.
        $list = ListFilterQuery::parse($query, self::KEYS, self::SORTS, 'step', ['has_data' => $defaultHasData], $defaultRows, true, 'Invalid profile filters.');

        return new self($list->filter, $list->page, $list->rows, $list->rowsParam, $list->sortColumn, $list->sortDirection, $list->flags['has_data']);
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
            'has_data' => $this->hasData ? 'true' : 'false',
        ];
    }
}
