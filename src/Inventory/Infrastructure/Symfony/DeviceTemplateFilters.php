<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony;

use Kadupul\Inventory\Domain\DeviceTemplateDefinition;

final class DeviceTemplateFilters
{
    public const SIZES = [10, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 30, 40, 44, 45, 50, 100, 250, 500, 750, 1000, 2000, 3000, 4000, 5000];
    public static function parse(array $query, array $defaults = []): array
    {
        $result = ['q' => '', 'has_hosts' => 'false', 'class' => '-1', 'graph' => 0, 'page' => 1, 'size' => 25, 'sort' => 'name', 'direction' => 'asc'];
        $result = array_replace($result, array_intersect_key($defaults, $result));
        foreach ($result as $key => $default) {
            if (!array_key_exists($key, $query)) {
                continue;
            }
            $value = $query[$key];
            if (!is_scalar($value) || is_bool($value)) {
                throw new \InvalidArgumentException();
            }
            if (is_int($default)) {
                if (!preg_match('/^[0-9]{1,8}$/D', (string) $value)) {
                    throw new \InvalidArgumentException();
                }
                $result[$key] = (int) $value;
            } else {
                $result[$key] = (string) $value;
            }
        }
        if (!in_array($result['has_hosts'], ['true', 'false'], true) || $result['page'] < 1 || $result['page'] > 100000 || !in_array($result['size'], self::SIZES, true)
            || !in_array($result['sort'], ['id', 'name', 'class', 'hosts'], true) || !in_array($result['direction'], ['asc', 'desc'], true)
            || !in_array($result['class'], ['-1', '', ...DeviceTemplateDefinition::CLASSES], true)
            || strlen($result['q']) > 200 || !mb_check_encoding($result['q'], 'UTF-8') || str_contains($result['q'], "\0")) {
            throw new \InvalidArgumentException();
        }
        return $result;
    }
}
