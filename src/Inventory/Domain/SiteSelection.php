<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class SiteSelection
{
    public array $revisions;

    public function __construct(array $revisions)
    {
        self::validateIds(array_keys($revisions));
        foreach ($revisions as $revision) {
            if (!is_string($revision) || !preg_match('/\A[a-f0-9]{64}\z/D', $revision)) {
                throw new \InvalidArgumentException('Invalid site selection.');
            }
        }
        ksort($revisions, SORT_NUMERIC);
        $this->revisions = $revisions;
    }

    public static function validateIds(array $ids): array
    {
        if ($ids === [] || count($ids) > 100) {
            throw new \InvalidArgumentException('Select between 1 and 100 sites.');
        }
        $normalized = [];
        foreach ($ids as $id) {
            if ((!is_int($id) && !is_string($id)) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $id) || (int) $id > 4294967295) {
                throw new \InvalidArgumentException('Invalid site selection.');
            }
            $normalized[] = (int) $id;
        }
        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new \InvalidArgumentException('Invalid site selection.');
        }
        sort($normalized, SORT_NUMERIC);
        return $normalized;
    }
}
