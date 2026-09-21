<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony;

use Kadupul\Inventory\Domain\SiteListCriteria;

final class SiteListParameters
{
    public static function parse(array $query): SiteListCriteria
    {
        foreach (['q', 'page', 'size', 'direction'] as $key) {
            if (isset($query[$key]) && !is_string($query[$key])) {
                throw new \InvalidArgumentException('Invalid site list filters.');
            }
        }
        $page = $query['page'] ?? '1';
        $size = $query['size'] ?? '25';
        if (!ctype_digit($page) || !ctype_digit($size) || strlen($page) > 6 || strlen($size) > 3) {
            throw new \InvalidArgumentException('Invalid site list filters.');
        }
        return new SiteListCriteria($query['q'] ?? '', (int) $page, (int) $size, $query['direction'] ?? 'asc');
    }

    public static function context(array $query): array
    {
        $list = $query['list'] ?? [];
        if (!is_array($list)) {
            throw new \InvalidArgumentException('Invalid site list filters.');
        }
        return self::encode(self::parse($list));
    }

    public static function encode(SiteListCriteria $criteria): array
    {
        return ['q' => $criteria->search, 'page' => $criteria->page, 'size' => $criteria->pageSize, 'direction' => $criteria->direction];
    }
}
