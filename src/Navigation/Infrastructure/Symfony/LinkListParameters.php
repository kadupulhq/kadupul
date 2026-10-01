<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Symfony;

final class LinkListParameters
{
    public static function parse(array $query, int $defaultRows = 25): array
    {
        $q = $query['filter'] ?? '';
        if (!is_string($q) || mb_strlen($q, 'UTF-8') > 200 || preg_match('//u', $q) !== 1 || str_contains($q, "\0")) {
            throw new \InvalidArgumentException('Invalid link list filters.');
        }
        $rows = $query['rows'] ?? '-1';
        $page = $query['page'] ?? '1';
        foreach ([$rows, $page] as $number) {
            if (!is_string($number) || preg_match('/^(?:-1|[1-9][0-9]{0,6})$/D', $number) !== 1) {
                throw new \InvalidArgumentException('Invalid link list filters.');
            }
        }
        if (!in_array((int) $rows, [-1, 10, 15, 20, 25, 30, 40, 50, 100, 250, 500, 1000, 2000, 5000], true) || (int) $page < 1) {
            throw new \InvalidArgumentException('Invalid link list filters.');
        }
        $sort = $query['sort_column'] ?? 'sortorder';
        $direction = $query['sort_direction'] ?? 'ASC';
        if (!is_string($sort) || !in_array($sort, ['sortorder', 'contentfile', 'title', 'style', 'enabled'], true) || !is_string($direction) || !in_array(strtoupper($direction), ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException('Invalid link list filters.');
        }
        return ['filter' => $q, 'rows' => $rows, 'page' => $page, 'sort_column' => $sort, 'sort_direction' => $sort === 'sortorder' ? 'ASC' : strtoupper($direction), 'limit' => $rows === '-1' ? $defaultRows : (int) $rows];
    }
    public static function ids(mixed $values): array
    {
        if (!is_array($values) || $values === [] || count($values) > 100) {
            throw new \InvalidArgumentException('Invalid link selection.');
        }
        $ids = [];
        foreach ($values as $value) {
            if (!is_string($value) || preg_match('/^[1-9][0-9]{0,7}$/D', $value) !== 1 || (int) $value > 16767215) {
                throw new \InvalidArgumentException('Invalid link selection.');
            }
            $ids[] = (int) $value;
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new \InvalidArgumentException('Invalid link selection.');
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
