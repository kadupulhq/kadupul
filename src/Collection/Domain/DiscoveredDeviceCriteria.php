<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Domain;

final readonly class DiscoveredDeviceCriteria
{
    public string $search;

    public function __construct(
        string $search = '',
        public ?int $networkId = null,
        public string $status = 'all',
        public string $snmp = 'all',
        public string $os = '',
        public int $page = 1,
        public int $pageSize = 25,
        public string $sort = 'hostname',
        public string $direction = 'asc'
    ) {
        $this->search = trim($search);
        if (!mb_check_encoding($this->search, 'UTF-8') || strlen($this->search) > 200 || str_contains($this->search, "\0")
            || ($networkId !== null && $networkId < 1) || !in_array($status, ['all', 'up', 'down'], true)
            || !in_array($snmp, ['all', 'up', 'down'], true) || !mb_check_encoding($os, 'UTF-8') || strlen($os) > 64
            || str_contains($os, "\0") || $page < 1 || $page > 100000 || !in_array($pageSize, [25, 50, 100], true)
            || !in_array($sort, ['hostname', 'ip', 'sysName', 'sysLocation', 'sysContact', 'sysDescr', 'os', 'time', 'snmp', 'up'], true)
            || !in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Invalid automation device filters.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }
}
