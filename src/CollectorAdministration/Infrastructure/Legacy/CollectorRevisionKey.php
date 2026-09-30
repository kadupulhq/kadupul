<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Legacy;

use Kadupul\Platform\Contract\LegacyConfiguration;

/** Derives an opaque revision MAC key from the installation's CSRF secret. */
final readonly class CollectorRevisionKey
{
    public function __construct(private LegacyConfiguration $configuration) {}

    public function get(): string
    {
        $settings = $this->configuration->values();
        $root = $settings['root'] ?? null;
        $path = $settings['csrf_secret_path'] ?? null;
        if (!is_string($path) || $path === '') {
            if (!is_string($root) || $root === '') {
                throw new \RuntimeException('Collector revision key is unavailable.');
            }
            $path = rtrim($root, DIRECTORY_SEPARATOR) . '/include/vendor/csrf/csrf-secret.php';
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || is_link($path)
            || ($stat['mode'] & 0022) !== 0 || $stat['size'] < 32 || $stat['size'] > 4096) {
            throw new \RuntimeException('Collector revision key is unavailable.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Collector revision key is unavailable.');
        }
        try {
            $opened = fstat($handle);
            clearstatcache(true, $path);
            $current = @lstat($path);
            $secret = stream_get_contents($handle, 4097);
            if ($opened === false || $current === false || ($opened['mode'] & 0170000) !== 0100000
                || ($current['mode'] & 0170000) !== 0100000 || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']
                || !is_string($secret) || strlen($secret) < 32 || strlen($secret) > 4096) {
                throw new \RuntimeException('Collector revision key is unavailable.');
            }
        } finally {
            fclose($handle);
        }
        return hash_hmac('sha256', 'kadupul.collector.revision.v1', $secret, true);
    }
}
