<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

/**
 * The installation values include/global.php puts into $config once and that
 * nothing writes afterwards. The $config keys remain the compatibility
 * contract for pages, plugins and the poller; this is the typed copy for code
 * that is handed its dependencies.
 *
 * rra_path is left out because rrd_maintenance_lock_directories() rewrites and
 * unsets it after bootstrap. is_web is left out because global.php decides it
 * per entry script after the libraries load, and the kernel has no such
 * script to decide it from.
 */
final readonly class PlatformConfiguration
{
    public function __construct(
        /** $config['base_path'] */
        public string $basePath,
        /** $config['url_path'] */
        public string $urlPath,
        /** $config['library_path'] */
        public string $libraryPath,
        /** $config['include_path'] */
        public string $includePath,
        /** $config['poller_id'] */
        public int $pollerId,
        /** $config['cacti_server_os'], 'win32' or 'unix' */
        public string $serverOs,
    ) {}

    /**
     * Applies global.php's rules to the inputs global.php reads: the include/
     * directory it runs from, $poller_id as config.php leaves it (null when
     * unset), $url_path as config.php leaves it ('/cacti/', global.php's
     * default, when config.php does not assign it) and PHP_OS.
     *
     * The array keeps $poller_id as config.php wrote it. The object holds the
     * integer InstallationConfiguration already derives for collector_id, so
     * the two differ in type only when config.php quotes the number.
     */
    public static function fromBootstrap(string $includeDirectory, mixed $pollerId, mixed $urlPath, string $os): self
    {
        $serverOs = str_contains($os, 'WIN') ? 'win32' : 'unix';
        if ($serverOs === 'win32') {
            $basePath = str_replace('\\', '/', substr($includeDirectory, 0, -8));
            $libraryPath = $basePath . '/lib';
        } else {
            $basePath = (string) preg_replace('/(.*)[\/]include/', '\1', $includeDirectory);
            $libraryPath = (string) preg_replace('/(.*[\/])include/', '\1lib', $includeDirectory);
        }

        if (empty($urlPath)) {
            $urlPath = '/';
        }
        if (!\is_string($urlPath)) {
            throw new \InvalidArgumentException('config.php sets $url_path to a ' . get_debug_type($urlPath) . ', not a string.');
        }

        $pollerId = (int) ($pollerId ?? 1);
        if ($pollerId < 1) {
            throw new \InvalidArgumentException('Invalid collector identity.');
        }

        return new self($basePath, $urlPath, $libraryPath, $includeDirectory, $pollerId, $serverOs);
    }
}
