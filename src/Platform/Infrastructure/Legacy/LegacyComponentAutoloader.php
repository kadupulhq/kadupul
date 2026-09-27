<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Load only the platform components needed by legacy entry points.
 *
 * Legacy tools can run beside another Composer application. Registering the
 * full application autoloader there can introduce unrelated dependency
 * versions into the caller's process.
 */
final class LegacyComponentAutoloader
{
    /** Ensure Kadupul Clock and Symfony Clock, Filesystem, and Process classes are loadable.
     *
     * @param string $projectRoot Absolute Kadupul project root.
     *
     * @return void
     */
    public static function register(string $projectRoot): void
    {
        if (class_exists(\Kadupul\Platform\Infrastructure\Symfony\SystemClock::class)
            && class_exists(\Symfony\Component\Clock\NativeClock::class)
            && class_exists(\Symfony\Component\Filesystem\Filesystem::class)
            && class_exists(\Symfony\Component\Process\Process::class)) {
            return;
        }

        if (!class_exists(\Composer\Autoload\ClassLoader::class)) {
            require_once __DIR__ . '/../../../../include/vendor/autoload.php';

            return;
        }

        static $loader = null;
        if ($loader !== null) {
            return;
        }

        $vendor = $projectRoot . '/include/vendor';
        $loader = new \Composer\Autoload\ClassLoader();
        $loader->addPsr4('Kadupul\\Platform\\', $projectRoot . '/src/Platform/');
        $loader->addPsr4('Psr\\Clock\\', $vendor . '/psr/clock/src/');
        $loader->addPsr4('Symfony\\Component\\Clock\\', $vendor . '/symfony/clock/');
        $loader->addPsr4('Symfony\\Component\\Filesystem\\', $vendor . '/symfony/filesystem/');
        $loader->addPsr4('Symfony\\Component\\Process\\', $vendor . '/symfony/process/');
        $loader->register();
    }
}
