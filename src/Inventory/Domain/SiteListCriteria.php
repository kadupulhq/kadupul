<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * Validated search, paging and ordering for the site list.
 */
final readonly class SiteListCriteria
{
    public string $search;

    /**
     * Validates and trims the criteria.
     * Page size must be 25, 50 or 100.
     *
     * @param string $sort One of "name", "id", "devices", "city", "state" or "country".
     * @param string $direction Either "asc" or "desc".
     * @throws \InvalidArgumentException When any value is outside its allowed set or range.
     */
    public function __construct(string $search = '', public int $page = 1, public int $pageSize = 25, public string $direction = 'asc', public string $sort = 'name')
    {
        $this->search = trim($search);
        if (strlen($this->search) > 200 || !mb_check_encoding($this->search, 'UTF-8') || str_contains($this->search, "\0")
            || $page < 1 || $page > 100000 || !in_array($pageSize, [25, 50, 100], true)
            || !in_array($sort, ['name', 'id', 'devices', 'city', 'state', 'country'], true)
            || !in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Invalid site list filters.');
        }
    }

    /**
     * Returns the zero-based row offset of the first row on the requested page.
     */
    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }
}
