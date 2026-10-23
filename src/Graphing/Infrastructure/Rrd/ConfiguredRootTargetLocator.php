<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Infrastructure\Rrd;

use Kadupul\Graphing\Application\Port\RrdTarget;
use Kadupul\Graphing\Application\Port\RrdTargetLocator;
use Kadupul\Graphing\Application\Port\StorageRejected;
use Kadupul\Monitoring\Contract\StorageReference;
use Kadupul\Telemetry\Contract\RejectionReason;

/**
 * Maps `local-data:<id>` references to .rrd files below one configured
 * root. There is no default root: a deployment names it explicitly, so the
 * writer cannot fall back to an existing graph directory.
 *
 * The real path is checked after symlinks are resolved and that resolved
 * path is what rrdtool receives, so a link or `..` cannot lead outside the
 * root. A component replaced by a symlink between this check and the
 * rrdtool call is not caught; keeping the root writable only by the
 * collector account is what closes that gap.
 */
final class ConfiguredRootTargetLocator implements RrdTargetLocator
{
    private readonly string $root;

    /** @param array<int, string> $relativePaths file below the root for each local data id */
    public function __construct(string $root, private readonly array $relativePaths)
    {
        if (!str_starts_with($root, '/') || str_contains($root, "\0")) {
            throw new InvalidRrdConfiguration('The RRD root must be an absolute path to an existing directory.');
        }
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new InvalidRrdConfiguration(sprintf('The RRD root %s must be an absolute path to an existing directory.', $root));
        }
        if ($real === '/') {
            throw new InvalidRrdConfiguration('The RRD root must not be the filesystem root, which would contain every file.');
        }
        $this->root = $real;
    }

    public function locate(StorageReference $reference): RrdTarget
    {
        $name = $reference->toString();
        if (preg_match('/\Alocal-data:([1-9][0-9]{0,17})\z/', $name, $m) !== 1) {
            throw new StorageRejected(RejectionReason::TargetMissing, sprintf('Storage reference %s is not a local data-source reference this writer handles.', $name));
        }
        $relative = $this->relativePaths[(int) $m[1]] ?? null;
        if ($relative === null) {
            throw new StorageRejected(RejectionReason::TargetMissing, sprintf('No RRD file is configured for storage reference %s.', $name));
        }
        if (!$this->isPlainRelative($relative)) {
            throw new StorageRejected(RejectionReason::StorageFailure, sprintf('The file configured for %s is not a plain relative .rrd path below the RRD root.', $name));
        }
        $candidate = $this->root . '/' . $relative;
        // A cached realpath could predate a symlink being swapped in.
        clearstatcache(true, $candidate);
        $real = realpath($candidate);
        if ($real === false) {
            throw new StorageRejected(RejectionReason::TargetMissing, sprintf('The RRD file for %s does not exist.', $name));
        }
        if (!str_starts_with($real, $this->root . '/')) {
            throw new StorageRejected(RejectionReason::StorageFailure, sprintf('The file for %s resolves outside the configured RRD root.', $name));
        }
        if (!is_file($real) || !str_ends_with($real, '.rrd')) {
            throw new StorageRejected(RejectionReason::StorageFailure, sprintf('The file for %s is not a regular .rrd file.', $name));
        }

        return new RrdTarget($real);
    }

    private function isPlainRelative(string $relative): bool
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, "\0") || str_contains($relative, '\\') || !str_ends_with($relative, '.rrd')) {
            return false;
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
