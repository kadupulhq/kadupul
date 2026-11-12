<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * Validated filters, paging and ordering for the device list.
 */
final readonly class DeviceListCriteria
{
    public string $search;

    /**
     * Validates and trims the filters.
     * A null site, template, collector or location filter means no filtering on that field. Page size must be 25, 50 or 100.
     *
     * @param string $state One of "all", "enabled" or "disabled".
     * @param string $status One of "all", "unknown", "down", "recovering", "up", "error", "disabled" or "not-up".
     * @throws \InvalidArgumentException When any value is outside its allowed set or range.
     */
    public function __construct(string $search = '', public string $state = 'all', public int $page = 1, public int $pageSize = 25, public string $status = 'all', public DeviceOrder $order = new DeviceOrder(), public ?int $siteId = null, public ?int $templateId = null, public ?int $collectorId = null, public ?string $location = null)
    {
        $this->search = trim($search);
        if (strlen($this->search) > 200 || !in_array($state, ['all', 'enabled', 'disabled'], true)
            || !in_array($status, ['all', 'unknown', 'down', 'recovering', 'up', 'error', 'disabled', 'not-up'], true)
            || ($siteId !== null && ($siteId < 0 || $siteId > 4294967295))
            || ($templateId !== null && ($templateId < 0 || $templateId > 16777215))
            || ($collectorId !== null && ($collectorId < 1 || $collectorId > 16777215))
            || ($location !== null && (mb_strlen($location, 'UTF-8') > 255 || !mb_check_encoding($location, 'UTF-8') || str_contains($location, "\0")))
            || $page < 1 || $page > 100000 || !in_array($pageSize, [25, 50, 100], true)) {
            throw new \InvalidArgumentException('Invalid device list filters.');
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
