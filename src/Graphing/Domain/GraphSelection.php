<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/**
 * Which graphs a bulk tool acts on: every device, device 0 (graphs that belong
 * to no device), or a list of device ids, optionally narrowed by a title or
 * template-name fragment. The grammar is what lib/reapply_names.php accepted:
 * "all", "0" or comma-separated ids from 1 to 4294967295.
 */
final readonly class GraphSelection
{
    private const int MAX_DEVICE_ID = 4294967295;

    /** @param ?list<int> $hostIds null selects every device */
    private function __construct(public ?array $hostIds, public string $filter) {}

    /**
     * @param string $hosts "all", "0" or comma-separated device ids
     * @return ?self null when $hosts is none of those
     */
    public static function parse(string $hosts, string $filter = ''): ?self
    {
        if (strtolower($hosts) === 'all') {
            return new self(null, $filter);
        }
        if ($hosts === '0') {
            return new self([0], $filter);
        }
        $ids = [];
        foreach (explode(',', $hosts) as $host) {
            if (!ctype_digit($host) || (int) $host < 1 || (int) $host > self::MAX_DEVICE_ID) {
                return null;
            }
            $ids[] = (int) $host;
        }

        return new self($ids, $filter);
    }
}
