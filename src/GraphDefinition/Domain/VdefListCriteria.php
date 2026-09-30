<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

final readonly class VdefListCriteria
{
    public function __construct(
        public string $search = '',
        public int $page = 1,
        public int $pageSize = 30,
        public string $sort = 'name',
        public string $direction = 'asc',
        public bool $hasGraphs = false,
    ) {
        if ($page < 1 || $page > 1000000 || $pageSize < 1 || $pageSize > 500
            || !in_array($sort, ['name', 'graphs', 'templates'], true)
            || !in_array($direction, ['asc', 'desc'], true)
            || mb_strlen($search) > 255 || preg_match('/[\x00]/', $search)) {
            throw new \InvalidArgumentException('Invalid VDEF list options.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }
}
