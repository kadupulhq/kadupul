<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Domain;

final readonly class AutomationGraphRuleCriteria
{
    public string $search;

    public function __construct(
        string $search = '',
        public string $status = 'all',
        public int $dataQueryId = -1,
        public int $page = 1,
        public int $pageSize = 25,
        public string $sort = 'name',
        public string $direction = 'asc'
    ) {
        $this->search = trim($search);
        if (!mb_check_encoding($this->search, 'UTF-8') || strlen($this->search) > 200 || str_contains($this->search, "\0")
            || !in_array($status, ['all', 'enabled', 'disabled'], true)
            || $dataQueryId < -1 || $dataQueryId > 65535 || $page < 1 || $page > 100000 || !in_array($pageSize, [25, 50, 100], true)
            || !in_array($sort, ['name', 'data_query', 'graph_type', 'enabled', 'id'], true)
            || !in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Invalid automation graph rule filters.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }
}
