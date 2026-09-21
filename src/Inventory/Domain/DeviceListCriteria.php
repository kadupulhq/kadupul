<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class DeviceListCriteria
{
    public string $search;

    public function __construct(string $search = '', public string $state = 'all', public int $page = 1, public int $pageSize = 25, public string $status = 'all')
    {
        $this->search = trim($search);
        if (strlen($this->search) > 200 || !in_array($state, ['all', 'enabled', 'disabled'], true)
            || !in_array($status, ['all', 'unknown', 'down', 'recovering', 'up', 'disabled'], true)
            || $page < 1 || $page > 100000 || !in_array($pageSize, [25, 50, 100], true)) {
            throw new \InvalidArgumentException('Invalid device list filters.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }
}
