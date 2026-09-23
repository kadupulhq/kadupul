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
        foreach (['q', 'state', 'status', 'sort', 'direction', 'page', 'size', 'site', 'template', 'collector', 'location', 'location_mode'] as $key) {
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
        $ids = [];
        foreach (['template', 'collector'] as $key) {
            $value = $query[$key] ?? '';
            if ($value !== '' && (!ctype_digit($value) || strlen($value) > 8)) {
                throw new \InvalidArgumentException('Invalid device list filters.');
            }
            $ids[$key] = $value === '' ? null : (int) $value;
        }
        if (!in_array($query['location_mode'] ?? 'all', ['all', 'exact'], true)) {
            throw new \InvalidArgumentException('Invalid device list filters.');
        }
        return new DeviceListCriteria($query['q'] ?? '', $query['state'] ?? 'all', (int) $page, (int) $size, $query['status'] ?? 'all', new DeviceOrder($query['sort'] ?? 'name', $query['direction'] ?? 'asc'), $site === '' ? null : (int) $site, $ids['template'], $ids['collector'], ($query['location_mode'] ?? 'all') === 'exact' ? ($query['location'] ?? '') : null);
    }

    public static function context(array $query): array
    {
        $list = $query['list'] ?? [];
        if (!is_array($list)) {
            throw new \InvalidArgumentException('Invalid device list filters.');
        }
        return self::encode(self::parse($list));
    }

    public static function encode(DeviceListCriteria $criteria): array
    {
        return ['q' => $criteria->search, 'state' => $criteria->state, 'status' => $criteria->status,
            'sort' => $criteria->order->field, 'direction' => $criteria->order->direction,
            'page' => $criteria->page, 'size' => $criteria->pageSize, 'site' => $criteria->siteId ?? '', 'template' => $criteria->templateId ?? '', 'collector' => $criteria->collectorId ?? '', 'location_mode' => $criteria->location === null ? 'all' : 'exact', 'location' => $criteria->location ?? ''];
    }
}
