<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

interface BoostImageCache
{
    /** Whether the boost_png_cache_enable setting is on. */
    public function isEnabled(): bool;

    /**
     * Deletes the regular files Boost names for cached graphs, and the temporary files an
     * interrupted cache write leaves, directly inside the configured cache directory and older
     * than $seconds. Symlinks, other names and subdirectories are left.
     *
     * @return int the number of files removed
     * @throws BoostImageCacheUnavailable when the cache directory is not configured or cannot be used
     */
    public function purgeOlderThan(int $seconds): int;
}
