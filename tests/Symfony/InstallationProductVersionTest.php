<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\InstallationProductVersion;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class InstallationProductVersionTest extends TestCase
{
    public function testVersionAndOptionalBetaReadWithoutExecutingGlobals(): void
    {
        $root = sys_get_temp_dir() . '/kadupul-about-version-' . bin2hex(random_bytes(8));
        $fs = new Filesystem();
        $fs->mkdir($root . '/include');
        try {
            $fs->dumpFile($root . '/include/cacti_version', " 1.2.31\n");
            foreach (["// define('CACTI_VERSION_BETA', 1);" => null, "if (false) { define('CACTI_VERSION_BETA', 8); }" => null, "if (false) define('CACTI_VERSION_BETA', 8);" => null, "define('CACTI_VERSION_BETA', 2);" => '2', "define('CACTI_VERSION_BETA', '<beta>');" => '<beta>'] as $source => $beta) {
                $fs->dumpFile($root . '/include/global.php', "<?php\nthrow new \RuntimeException('must not execute');\n" . $source);
                $release = (new InstallationProductVersion($root, $fs))->release();
                self::assertSame('1.2.31', $release->version);
                self::assertSame($beta, $release->beta);
            }
            self::assertFalse(defined('CACTI_VERSION_BETA'));
        } finally {
            $fs->remove($root);
        }
    }
}
