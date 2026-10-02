<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class DeviceSelection
{
    public array $revisions;

    public function __construct(array $revisions)
    {
        self::validateIds(array_keys($revisions));
        foreach ($revisions as $revision) {
            if (!is_string($revision) || !preg_match('/\A[a-f0-9]{64}\z/D', $revision)) {
                throw new \InvalidArgumentException('Invalid device selection.');
            }
        }
        ksort($revisions, SORT_NUMERIC);
        $this->revisions = $revisions;
    }

    public static function validateIds(array $ids): array
    {
        return SelectionIds::normalize($ids, 8, 16777215, 'Select between 1 and 100 devices.', 'Invalid device selection.');
    }
}
