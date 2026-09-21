<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony;

use Kadupul\Inventory\Domain\DeviceListCriteria;
use Kadupul\Inventory\Domain\DeviceOrder;

final class DeviceListParameters
{
    public static function parse(array $query): DeviceListCriteria
    {
        foreach (['q', 'state', 'status', 'sort', 'direction', 'page', 'size', 'site'] as $key) {
            if (isset($query[$key]) && !is_string($query[$key])) {
                throw new \InvalidArgumentException('Invalid device list filters.');
            }
        }
        $page = $query['page'] ?? '1';
        $size = $query['size'] ?? '25';
        if (!ctype_digit($page) || !ctype_digit($size) || strlen($page) > 6 || strlen($size) > 3) {
            throw new \InvalidArgumentException('Invalid device list filters.');
        }
        $site = $query['site'] ?? '';
        if ($site !== '' && (!ctype_digit($site) || strlen($site) > 10)) {
            throw new \InvalidArgumentException('Invalid device site.');
        }
        return new DeviceListCriteria($query['q'] ?? '', $query['state'] ?? 'all', (int) $page, (int) $size, $query['status'] ?? 'all', new DeviceOrder($query['sort'] ?? 'name', $query['direction'] ?? 'asc'), $site === '' ? null : (int) $site);
    }

    public static function encode(DeviceListCriteria $criteria): array
    {
        return ['q' => $criteria->search, 'state' => $criteria->state, 'status' => $criteria->status,
            'sort' => $criteria->order->field, 'direction' => $criteria->order->direction,
            'page' => $criteria->page, 'size' => $criteria->pageSize, 'site' => $criteria->siteId ?? ''];
    }
}
