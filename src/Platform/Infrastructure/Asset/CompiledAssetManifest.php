<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Asset;

/**
 * Reads the manifest.json that asset-map:compile writes, without booting the kernel.
 *
 * Legacy pages emit dozens of includes per request, so the file is decoded at
 * most once per instance. A missing, unreadable or malformed manifest reads as
 * empty, which sends every lookup back to the caller's uncompiled path.
 */
final class CompiledAssetManifest
{
    /** @var array<string, string>|null */
    private ?array $entries = null;

    /**
     * @param string $manifestFile Absolute path of public/assets/manifest.json.
     * @param string $webPrefix    Path of the public directory below the web root, such as "public".
     */
    public function __construct(private readonly string $manifestFile, private readonly string $webPrefix) {}

    /**
     * The web-root-relative path of the compiled copy of $logicalPath, such as
     * "public/assets/include/js/jquery-3Xa9fQ1.js", or null when it has none.
     */
    public function publicPath(string $logicalPath): ?string
    {
        $this->entries ??= $this->load();
        $compiled = $this->entries[$logicalPath] ?? null;

        return $compiled === null ? null : $this->webPrefix . $compiled;
    }

    /** @return array<string, string> */
    private function load(): array
    {
        if (!is_file($this->manifestFile) || !is_readable($this->manifestFile)) {
            return [];
        }

        $json = file_get_contents($this->manifestFile);
        if ($json === false) {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $entries = [];
        foreach ($decoded as $logicalPath => $compiled) {
            // The result goes into a quoted HTML attribute unescaped, as the
            // uncompiled path always has, so accept only the plain root-relative
            // paths the compiler writes and drop anything that could leave the
            // public directory.
            if (is_string($logicalPath) && is_string($compiled) && self::isSafePublicPath($compiled)) {
                $entries[$logicalPath] = $compiled;
            }
        }

        return $entries;
    }

    private static function isSafePublicPath(string $path): bool
    {
        return preg_match('#^(?:/[A-Za-z0-9_@~.-]+)+$#D', $path) === 1
            && !preg_match('#/\.\.?(?:/|$)#', $path);
    }
}
