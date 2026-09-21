<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony;

use Kadupul\Inventory\Domain\DeviceListCriteria;

final class DeviceListParameters
{
    public static function parse(array $query): DeviceListCriteria
    {
        foreach (['q', 'state', 'status', 'sort', 'direction', 'page', 'size'] as $key) {
            if (isset($query[$key]) && !is_string($query[$key])) {
                throw new \InvalidArgumentException('Invalid device list filters.');
            }
        }
        $page = $query['page'] ?? '1';
        $size = $query['size'] ?? '25';
        if (!ctype_digit($page) || !ctype_digit($size) || strlen($page) > 6 || strlen($size) > 3) {
            throw new \InvalidArgumentException('Invalid device list filters.');
        }
        return new DeviceListCriteria($query['q'] ?? '', $query['state'] ?? 'all', (int) $page, (int) $size, $query['status'] ?? 'all', $query['sort'] ?? 'name', $query['direction'] ?? 'asc');
    }

    public static function encode(DeviceListCriteria $criteria): array
    {
        return ['q' => $criteria->search, 'state' => $criteria->state, 'status' => $criteria->status,
            'sort' => $criteria->sort, 'direction' => $criteria->direction,
            'page' => $criteria->page, 'size' => $criteria->pageSize];
    }
}
