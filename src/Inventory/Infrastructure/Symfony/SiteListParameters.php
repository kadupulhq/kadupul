<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony;

use Kadupul\Inventory\Domain\SiteListCriteria;

/**
 * Converts the site list's query string to validated criteria and back.
 */
final class SiteListParameters
{
    /**
     * Builds the list criteria from query parameters, applying defaults for absent ones.
     *
     * @param array $query Query parameters; every filter value must be a string.
     * @throws \InvalidArgumentException When a value is malformed or out of range.
     */
    public static function parse(array $query): SiteListCriteria
    {
        foreach (['q', 'page', 'size', 'direction', 'sort'] as $key) {
            if (isset($query[$key]) && !is_string($query[$key])) {
                throw new \InvalidArgumentException('Invalid site list filters.');
            }
        }
        $page = $query['page'] ?? '1';
        $size = $query['size'] ?? '25';
        if (!ctype_digit($page) || !ctype_digit($size) || strlen($page) > 6 || strlen($size) > 3) {
            throw new \InvalidArgumentException('Invalid site list filters.');
        }
        return new SiteListCriteria($query['q'] ?? '', (int) $page, (int) $size, $query['direction'] ?? 'asc', $query['sort'] ?? 'name');
    }

    /**
     * Reads the list filters carried in a form request's "list" parameter and returns them in normalized form.
     *
     * @param array $query Map from string to mixed.
     * @return array Map from string to int|string.
     * @throws \InvalidArgumentException When "list" is not an array or its filters are invalid.
     */
    public static function context(array $query): array
    {
        $list = $query['list'] ?? [];
        if (!is_array($list)) {
            throw new \InvalidArgumentException('Invalid site list filters.');
        }
        return self::encode(self::parse($list));
    }

    /**
     * Returns the criteria as query parameters, leaving out the sort when it is the default.
     *
     * @return array Map from string to int|string.
     */
    public static function encode(SiteListCriteria $criteria): array
    {
        return ['q' => $criteria->search, 'page' => $criteria->page, 'size' => $criteria->pageSize, 'direction' => $criteria->direction] + ($criteria->sort === 'name' ? [] : ['sort' => $criteria->sort]);
    }
}
