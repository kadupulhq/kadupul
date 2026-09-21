<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

use Kadupul\IdentityAccess\Application\Port\InvalidatedRowCache;

final class CleanInvalidatedRowCache
{
    public function __construct(private readonly InvalidatedRowCache $cache) {}

    public function __invoke(): int
    {
        $removed = 0;
        foreach ($this->cache->invalidations() as $invalidation) {
            $removed += $this->cache->remove($invalidation);
        }

        return $removed;
    }
}
