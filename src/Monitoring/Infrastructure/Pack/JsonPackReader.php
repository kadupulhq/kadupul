<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Pack;

use Kadupul\Monitoring\Domain\InvalidPack;
use Kadupul\Monitoring\Domain\PackViolation;

/**
 * Reads *.pack.json files. Objects decode as \stdClass so PackValidator can
 * tell {} from [], and integers beyond PHP_INT_MAX decode as strings so they
 * are rejected instead of silently becoming floats. YAML packs are not read:
 * no YAML parser is a dependency of this module, and JSON is the format the
 * schema and examples use.
 */
final class JsonPackReader
{
    private const SUFFIX = '.pack.json';

    /**
     * @return array<string, mixed> decoded documents keyed by resolved file path, in name order
     */
    public function readDirectory(string $directory): array
    {
        $resolved = realpath($directory);
        if ($resolved === false || !is_dir($resolved)) {
            throw new PackDirectoryUnreadable(sprintf('Pack directory %s does not exist or is not a directory.', $directory));
        }
        $names = scandir($resolved);
        if ($names === false) {
            throw new PackDirectoryUnreadable(sprintf('Pack directory %s cannot be listed.', $resolved));
        }
        $documents = [];
        foreach ($names as $name) {
            $path = $resolved . '/' . $name;
            if (str_ends_with($name, self::SUFFIX) && is_file($path)) {
                $documents[$path] = $this->readFile($path);
            }
        }

        return $documents;
    }

    public function readFile(string $path): mixed
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new PackDirectoryUnreadable(sprintf('Pack file %s cannot be read.', $path));
        }
        try {
            return json_decode($content, false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new InvalidPack($path, [new PackViolation('(document)', 'is not valid JSON: ' . $e->getMessage())]);
        }
    }
}
