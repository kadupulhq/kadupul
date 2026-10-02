<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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

    public function testVdefRoutesAreAllowedToUseOnlyOnlinePrimaryDatabaseOnCollectors(): void
    {
        $directory = sys_get_temp_dir() . '/kadupul-vdef-configuration-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        file_put_contents($directory . '/include/config.php', "<?php\n\$poller_id = 2;\n\$conn_mode = 'offline';\n\$database_hostname = 'collector-db';\n\$database_default = 'cacti';\n\$database_username = 'local';\n\$database_password = 'local';\n");
        try {
            $requests = new RequestStack();
            $request = Request::create('/graph-definitions/vdefs');
            $request->attributes->set('_route', 'graph_vdefs');
            $requests->push($request);
            try {
                (new InstallationConfiguration($directory, $requests))->values();
                self::fail('Offline collectors must not silently use local storage for VDEF administration.');
            } catch (\RuntimeException $error) {
                self::assertSame('Online primary configuration is required for collector administration.', $error->getMessage());
            }

            $request->attributes->set('_route', 'unsupported_route');
            try {
                (new InstallationConfiguration($directory, $requests))->values();
                self::fail('Unlisted collector routes must remain refused.');
            } catch (\RuntimeException $error) {
                self::assertSame('The Symfony application requires the primary MySQL installation outside supported online collector administration routes.', $error->getMessage());
            }
        } finally {
            unlink($directory . '/include/config.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }
}
