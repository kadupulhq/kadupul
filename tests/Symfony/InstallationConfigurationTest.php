<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstallationConfigurationTest extends TestCase
{
    #[DataProvider('readCredentials')]
    public function testItMapsReadOnlyDatabaseCredentials(bool $configured): void
    {
        // PHPUnit prints data-set values on failure, so the password stays out of the provider.
        $source = $configured ? "\$database_read_username = 'kadupul_read';\n\$database_read_password = 'read-secret';\n" : '';
        $username = $configured ? 'kadupul_read' : '';
        $password = $configured ? 'read-secret' : '';
        $directory = sys_get_temp_dir() . '/kadupul-configuration-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        try {
            file_put_contents($directory . '/include/config.php', "<?php\n\$database_username = 'kadupul';\n\$database_password = 'secret';\n" . $source);
            $values = (new InstallationConfiguration($directory))->values();

            self::assertSame($username, $values['read_username']);
            self::assertTrue($values['read_password'] === $password, 'The read-only password was not mapped.');
            self::assertSame('kadupul', $values['username']);
        } finally {
            unlink($directory . '/include/config.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }

    public static function readCredentials(): iterable
    {
        yield 'configured' => [true];
        yield 'unset' => [false];
    }
}
