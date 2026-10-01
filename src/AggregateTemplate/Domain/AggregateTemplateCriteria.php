<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\AggregateTemplate\Domain;

final readonly class AggregateTemplateCriteria
{
    public function __construct(
        public string $search = '',
        public int $page = 1,
        public int $pageSize = 30,
        public string $sort = 'name',
        public string $direction = 'asc',
        public bool $hasGraphs = false,
    ) {
        if ($page < 1 || !in_array($pageSize, [30, 50, 100], true)
            || !in_array($sort, ['name', 'graphs', 'source'], true)
            || !in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Invalid aggregate template list options.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }
}
