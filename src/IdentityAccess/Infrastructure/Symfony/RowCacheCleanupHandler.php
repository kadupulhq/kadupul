<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony;

use Kadupul\IdentityAccess\Application\Command\CleanInvalidatedRowCache;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RowCacheCleanupHandler
{
    public function __construct(private readonly CleanInvalidatedRowCache $clean, private readonly ?string $enabled) {}

    public function __invoke(RowCacheCleanup $message): void
    {
        if ($this->enabled !== '1') {
            throw new \RuntimeException('Row-cache scheduling is disabled.');
        }
        ($this->clean)();
    }
}
