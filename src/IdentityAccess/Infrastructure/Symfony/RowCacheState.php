<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Contracts\Cache\CacheInterface;

final class RowCacheState
{
    public function __construct(private readonly string $directory) {}

    public function lock(): LockInterface
    {
        $this->prepareDirectory();

        return (new LockFactory(new FlockStore($this->directory)))->createLock('row-cache');
    }

    public function cache(): CacheInterface
    {
        $this->prepareDirectory();

        return new FilesystemAdapter('row-cache', 0, $this->directory);
    }

    private function prepareDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create scheduler state directory.');
        }
    }
}
