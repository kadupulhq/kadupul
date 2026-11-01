<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/**
 * Which data query index items reorder_data_query.php acted on: one device or
 * all of them, and one data query or all of them. Each is a positive integer
 * or "all", in any case. An integer is cast as the script cast it, so one that
 * overflows saturates instead of being refused.
 */
final readonly class DataQuerySelection
{
    public const string BAD_HOST = "--host-id must be a positive integer or 'all'.";
    public const string BAD_QUERY = "--qid must be a positive query ID or 'all'.";

    /** @param ?int $hostId null selects every device @param ?int $queryId null selects every data query */
    private function __construct(public ?int $hostId, public ?int $queryId) {}

    /**
     * @param ?string $query null when --qid was not given
     * @throws \InvalidArgumentException with the script's own message
     */
    public static function parse(string $host, ?string $query): self
    {
        $hostId = null;
        if (strtolower($host) !== 'all') {
            if (!ctype_digit($host) || (int) $host < 1) {
                throw new \InvalidArgumentException(self::BAD_HOST);
            }
            $hostId = (int) $host;
        }
        if ($query === null || (strtolower($query) !== 'all' && (!ctype_digit($query) || (int) $query < 1))) {
            throw new \InvalidArgumentException(self::BAD_QUERY);
        }

        return new self($hostId, strtolower($query) === 'all' ? null : (int) $query);
    }
}
