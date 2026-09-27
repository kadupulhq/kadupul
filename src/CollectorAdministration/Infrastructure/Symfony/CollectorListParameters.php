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
        foreach (['q', 'size', 'sort', 'direction'] as $key) {
            if (isset($formData[$key]) && !is_string($formData[$key])) {
                throw new \InvalidArgumentException('Invalid collector list filters.');
            }
        }

        $size = $formData['size'] ?? '25';
        if (!ctype_digit($size) || strlen($size) > 3) {
            throw new \InvalidArgumentException('Invalid collector list filters.');
        }

        return new CollectorListCriteria(
            $formData['q'] ?? '',
            (int) $page,
            (int) $size,
            $formData['sort'] ?? 'name',
            $formData['direction'] ?? 'asc'
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

        return $data;
    }
}
