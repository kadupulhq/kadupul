<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class SiteListCriteria
{
    public string $search;

    public function __construct(string $search = '', public int $page = 1, public int $pageSize = 25, public string $direction = 'asc')
    {
        $this->search = trim($search);
        if (strlen($this->search) > 200 || !mb_check_encoding($this->search, 'UTF-8') || str_contains($this->search, "\0")
            || $page < 1 || $page > 100000 || !in_array($pageSize, [25, 50, 100], true)
            || !in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Invalid site list filters.');
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }
}
