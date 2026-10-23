<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Infrastructure\Rrd;

use Kadupul\Graphing\Application\Port\RrdTargetInspector;
use Kadupul\Graphing\Application\Port\RrdTargetLocator;
use Kadupul\Graphing\Application\Port\RrdUpdater;

/**
 * Chooses between the real RRD adapters and the refusing stand-in. An empty
 * setting means unconfigured; a non-empty but invalid one still throws
 * InvalidRrdConfiguration, because a typo must not silently disable storage.
 */
final class RrdStorageFactory
{
    /**
     * @param array<int, string> $relativePaths .rrd paths below the root, keyed by local data id
     */
    public function locator(string $root, array $relativePaths): RrdTargetLocator
    {
        return $root === '' ? new UnconfiguredRrdStorage() : new ConfiguredRootTargetLocator($root, $relativePaths);
    }

    public function inspector(string $binary): RrdTargetInspector
    {
        return $binary === '' ? new UnconfiguredRrdStorage() : new RrdtoolInspector(new RrdtoolProcess($binary));
    }

    public function updater(string $binary): RrdUpdater
    {
        return $binary === '' ? new UnconfiguredRrdStorage() : new RrdtoolUpdater(new RrdtoolProcess($binary));
    }
}
