<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

final readonly class CdefListCriteria
{
    public function __construct(
        public string $search = '',
        public int $page = 1,
        public int $pageSize = 30,
        public string $sort = 'name',
        public string $direction = 'asc',
        public bool $hasGraphs = false,
    ) {
        if ($page < 1 || $pageSize < 1 || $pageSize > 500 || !in_array($sort, ['name', 'graphs', 'templates'], true)
            || !in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Invalid CDEF list options.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    public function query(array $overrides = []): array
    {
        return array_replace([
            'filter' => $this->search,
            'page' => $this->page,
            'rows' => $this->pageSize,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'has_graphs' => $this->hasGraphs ? 'true' : 'false',
        ], $overrides);
    }
}
