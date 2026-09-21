<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Query;

use Kadupul\IdentityAccess\Application\Port\InvalidatedRowCache;

final class InspectInvalidatedRowCache
{
    public function __construct(private readonly InvalidatedRowCache $cache) {}

    /** @return list<RowCacheBacklog> */
    public function __invoke(): array
    {
        $backlog = [];
        foreach ($this->cache->invalidations() as $invalidation) {
            $backlog[] = new RowCacheBacklog($invalidation, $this->cache->count($invalidation));
        }

        return $backlog;
    }
}
