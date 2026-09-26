<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Resolves the legacy paths used for JavaScript and CSS includes.
 */
final readonly class LegacyIncludePathResolver
{
    public function __construct(private Filesystem $filesystem) {}

    public function existingRelativePath(string $path, string $basePath): string|false
    {
        $basePath = rtrim($basePath, '/') . '/';

        if ($this->exists($path)) {
            return str_replace($basePath, '', $path);
        }

        if ($this->exists($basePath . $path)) {
            return $path;
        }

        return false;
    }

    private function exists(string $path): bool
    {
        try {
            return $this->filesystem->exists($path);
        } catch (IOException) {
            // file_exists() treated paths outside PHP's supported path length as absent.
            return false;
        }
    }
}
