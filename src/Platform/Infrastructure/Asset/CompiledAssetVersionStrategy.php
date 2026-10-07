<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;

/**
 * Versions the "legacy" asset package the way get_md5_include_css() does.
 *
 * AssetMapper's default package digests a mapped file even before
 * asset-map:compile has run, and its /assets/ prefix ignores that the
 * repository root, not public/, is the web root. Both give URLs nothing
 * serves. This strategy returns the compiled public/assets/ copy when the
 * manifest lists it and the source path with an ?md5 query otherwise.
 */
final readonly class CompiledAssetVersionStrategy implements VersionStrategyInterface
{
    public function __construct(private CompiledAssetManifest $manifest, private string $projectDir) {}

    public function getVersion(string $path): string
    {
        if ($this->manifest->publicPath($path) !== null) {
            return '';
        }
        $file = $this->projectDir . '/' . $path;

        // Never hash a file outside the installation or a missing one.
        return !str_contains($path, '..') && is_file($file) ? (string) md5_file($file) : '';
    }

    public function applyVersion(string $path): string
    {
        $compiled = $this->manifest->publicPath($path);
        if ($compiled !== null) {
            return $compiled;
        }
        $version = $this->getVersion($path);

        return $version === '' ? $path : $path . '?' . $version;
    }
}
