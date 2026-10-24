<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Application\Port;

/**
 * An RRD file a locator has already confirmed lies inside the configured
 * root. The path never leaves Graphing: outcomes describe the file by its
 * storage reference instead.
 */
final readonly class RrdTarget
{
    public function __construct(public string $path)
    {
        if (!str_starts_with($path, '/') || !str_ends_with($path, '.rrd') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('An RRD target is an absolute path to a .rrd file.');
        }
    }
}
