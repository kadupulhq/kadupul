<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Domain;

final readonly class CollectorSelection
{
    /** @var list<int> */
    public array $ids;

    public function __construct(array $ids)
    {
        if ($ids === [] || count($ids) > 500 || array_keys($ids) !== range(0, count($ids) - 1)) {
            throw new \InvalidArgumentException('Select at least one data collector.');
        }
        $validated = [];
        foreach ($ids as $id) {
            if (!is_int($id) && !(is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) === 1)) {
                throw new \InvalidArgumentException('Invalid data collector selection.');
            }
            $id = (int) $id;
            if ($id < 1 || $id > 2147483647 || in_array($id, $validated, true)) {
                throw new \InvalidArgumentException('Invalid data collector selection.');
            }
            $validated[] = $id;
        }
        sort($validated, SORT_NUMERIC);
        $this->ids = $validated;
    }
}
