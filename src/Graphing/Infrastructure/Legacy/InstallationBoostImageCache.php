<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\BoostImageCache;
use Kadupul\Graphing\Application\Port\BoostImageCacheUnavailable;
use Kadupul\Graphing\Domain\BoostGraphCacheKey;
use Kadupul\Platform\Contract\DatabaseConnection;

/**
 * The cache directory is the boost_png_cache_directory setting, and must resolve to a strict
 * subdirectory of the installation, as poller_boost.php requires.
 */
final readonly class InstallationBoostImageCache implements BoostImageCache
{
    public function __construct(private DatabaseConnection $database, private string $projectDir) {}

    #[\Override]
    public function isEnabled(): bool
    {
        return $this->setting('boost_png_cache_enable') === 'on';
    }

    #[\Override]
    public function purgeOlderThan(int $seconds): int
    {
        $directory = $this->directory();
        $threshold = time() - $seconds;
        $names = scandir($directory);
        if ($names === false) {
            throw new BoostImageCacheUnavailable('The Boost image cache directory cannot be read.');
        }

        $removed = 0;
        foreach ($names as $name) {
            if (preg_match(BoostGraphCacheKey::FILE_PATTERN, $name) !== 1) {
                continue;
            }
            $path = $directory . '/' . $name;
            // lstat-based checks first: a link is never followed, even to a matching name.
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            $modified = filemtime($path);
            if ($modified === false || $modified >= $threshold) {
                continue;
            }
            if (@unlink($path)) {
                ++$removed;
            }
        }

        return $removed;
    }

    private function directory(): string
    {
        $configured = $this->setting('boost_png_cache_directory');
        if ($configured === null || $configured === '' || str_contains($configured, "\0")) {
            throw new BoostImageCacheUnavailable('boost_png_cache_directory is not set.');
        }
        $directory = realpath($configured);
        $base = realpath($this->projectDir);
        if ($directory === false || !is_dir($directory) || $base === false) {
            throw new BoostImageCacheUnavailable('boost_png_cache_directory is not a readable directory.');
        }
        if (!str_starts_with($directory, rtrim($base, '/') . '/')) {
            throw new BoostImageCacheUnavailable('boost_png_cache_directory must be a subdirectory of the installation.');
        }

        return $directory;
    }

    private function setting(string $name): ?string
    {
        try {
            $statement = $this->database->get()->prepare('SELECT value FROM settings WHERE name = ?');
            $statement->execute([$name]);
            $value = $statement->fetchColumn();
        } catch (\PDOException $error) {
            throw new BoostImageCacheUnavailable('The Boost image cache settings cannot be read.', 0, $error);
        }

        return $value === false || $value === null ? null : (string) $value;
    }
}
