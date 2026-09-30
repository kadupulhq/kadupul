<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Domain;

final readonly class CollectorListCriteria
{
    public const PAGE_SIZES = [10, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 30, 40, 44, 45, 50, 100, 250, 500, 750, 1000, 2000, 3000, 4000, 5000];
    public string $search;

    public function __construct(
        string $search = '',
        public int $page = 1,
        public int $pageSize = 25,
        public string $sort = 'name',
        public string $direction = 'asc',
        public int $refresh = 20
    ) {
        $this->search = trim($search);
        if (!mb_check_encoding($this->search, 'UTF-8') || strlen($this->search) > 200 || str_contains($this->search, "\0")
            || $page < 1 || $page > 100000 || !in_array($pageSize, self::PAGE_SIZES, true)
            || !in_array($sort, ['name', 'id', 'hostname', 'status', 'hosts', 'polling_time', 'snmp', 'script', 'server', 'last_update', 'last_status', 'last_sync'], true)
            || !in_array($direction, ['asc', 'desc'], true)
            || !in_array($refresh, [0, 5, 10, 20, 30, 45, 60, 120, 300], true)) {
            throw new \InvalidArgumentException('Invalid collector list filters.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }
}
