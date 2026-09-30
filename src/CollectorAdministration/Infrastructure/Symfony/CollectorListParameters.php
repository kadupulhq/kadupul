<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Symfony;

use Kadupul\CollectorAdministration\Domain\CollectorListCriteria;

final class CollectorListParameters
{
    public static function parse(array $query, array $formData): CollectorListCriteria
    {
        $page = $query['page'] ?? '1';
        if (!is_string($page) || !ctype_digit($page) || strlen($page) > 6) {
            throw new \InvalidArgumentException('Invalid collector list filters.');
        }
        if (array_diff(array_keys($formData), ['q', 'size', 'sort', 'direction', 'refresh']) !== []) {
            throw new \InvalidArgumentException('Invalid collector list filters.');
        }
        foreach (['q', 'size', 'sort', 'direction', 'refresh'] as $key) {
            if (array_key_exists($key, $formData) && !is_string($formData[$key])) {
                throw new \InvalidArgumentException('Invalid collector list filters.');
            }
        }

        $size = $formData['size'] ?? '25';
        if (!ctype_digit($size) || strlen($size) > 4) {
            throw new \InvalidArgumentException('Invalid collector list filters.');
        }
        $refresh = $formData['refresh'] ?? '20';
        if (!ctype_digit($refresh) || strlen($refresh) > 3) {
            throw new \InvalidArgumentException('Invalid collector list filters.');
        }

        return new CollectorListCriteria(
            $formData['q'] ?? '',
            (int) $page,
            (int) $size,
            $formData['sort'] ?? 'name',
            $formData['direction'] ?? 'asc',
            (int) $refresh
        );
    }

    public static function formData(array $query): array
    {
        if (array_diff(array_keys($query), ['collector_filter', 'page']) !== []) {
            throw new \InvalidArgumentException('Invalid collector list filters.');
        }
        $data = $query['collector_filter'] ?? [];
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid collector list filters.');
        }
        if (array_diff(array_keys($data), ['q', 'size', 'sort', 'direction', 'refresh']) !== []
            || array_filter($data, static fn(mixed $value): bool => !is_string($value)) !== []) {
            throw new \InvalidArgumentException('Invalid collector list filters.');
        }

        return $data;
    }
}
