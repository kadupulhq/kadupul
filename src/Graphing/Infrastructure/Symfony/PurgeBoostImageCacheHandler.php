<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\PurgeBoostImageCache;
use Kadupul\Graphing\Application\Port\BoostImageCache;
use Kadupul\Graphing\Application\Port\BoostImageCacheUnavailable;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/** Purges expired Boost images on the hourly message, only while boost_png_cache_enable is on. */
#[AsMessageHandler]
final readonly class PurgeBoostImageCacheHandler
{
    public function __construct(private BoostImageCache $cache) {}

    public function __invoke(PurgeBoostImageCache $message): void
    {
        // The schedule is built without a database read, so the setting is checked here.
        if (!$this->cache->isEnabled()) {
            return;
        }
        try {
            $this->cache->purgeOlderThan(PurgeBoostImageCache::EXPIRY_SECONDS);
        } catch (BoostImageCacheUnavailable $error) {
            // The next hourly message retries.
            throw new UnrecoverableMessageHandlingException($error->getMessage(), 0, $error);
        }
    }
}
