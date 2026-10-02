<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/** Common batch shape and identity contract; resource-specific bounds stay with callers. */
final class SelectionIds
{
    /** IDs are admitted by the resource-specific caller before revision validation. */
    public static function normalizeRevisions(array $revisions, string $identityError): array
    {
        foreach ($revisions as $revision) {
            if (!is_string($revision) || !preg_match('/\A[a-f0-9]{64}\z/D', $revision)) {
                throw new \InvalidArgumentException($identityError);
            }
        }
        ksort($revisions, SORT_NUMERIC);
        return $revisions;
    }

    public static function normalize(array $ids, int $digits, int $maximum, string $cardinalityError, string $identityError): array
    {
        if ($ids === [] || count($ids) > 100) {
            throw new \InvalidArgumentException($cardinalityError);
        }
        if (!array_is_list($ids)) {
            throw new \InvalidArgumentException($identityError);
        }
        $normalized = [];
        foreach ($ids as $id) {
            if ((!is_int($id) && !is_string($id)) || !preg_match('/\A[1-9][0-9]{0,' . ($digits - 1) . '}\z/D', (string) $id) || (int) $id > $maximum) {
                throw new \InvalidArgumentException($identityError);
            }
            $normalized[] = (int) $id;
        }
        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new \InvalidArgumentException($identityError);
        }
        sort($normalized, SORT_NUMERIC);
        return $normalized;
    }
}
