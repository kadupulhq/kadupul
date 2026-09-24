<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\CollectorIdentity;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class InstallationConfigurationTargetsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kadupul-targets-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/include', 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    private function config(string $body): InstallationConfiguration
    {
        file_put_contents($this->root . '/include/config.php', "<?php\n\$database_type = 'mysql';\n\$database_default = 'kadupul';\n\$database_hostname = 'local.db';\n\$database_username = 'u';\n\$database_password = 'p';\n" . $body);

        return new InstallationConfiguration($this->root);
    }

    public function testPrimaryTargetsLocalAsMain(): void
    {
        $targets = $this->config('')->databaseTargets();
        self::assertSame(1, $targets['collector_id']);
        self::assertSame('local.db', $targets['local']['host']);
        self::assertSame($targets['local'], $targets['main']);
    }

    public function testCollectorTargetsReadRemoteDatabase(): void
    {
        $targets = $this->config("\$poller_id = 3;\n\$rdatabase_type = 'mysql';\n\$rdatabase_default = 'main';\n\$rdatabase_hostname = 'main.db';\n\$rdatabase_username = 'ru';\n\$rdatabase_password = 'rp';\n")->databaseTargets();
        self::assertSame(3, $targets['collector_id']);
        self::assertSame('local.db', $targets['local']['host']);
        self::assertSame('main.db', $targets['main']['host']);
        self::assertSame('main', $targets['main']['database']);
    }

    public function testCollectorTargetsWithoutRemoteDatabaseHaveNoMain(): void
    {
        $configuration = $this->config("\$poller_id = 3;\n");
        self::assertNull($configuration->databaseTargets()['main']);
        self::assertTrue((new CollectorIdentity($configuration))->isRemoteCollector());
    }

    public function testNonMysqlLocalDatabaseIsRefused(): void
    {
        $configuration = $this->config("\$database_type = 'pgsql';\n");
        $this->expectExceptionMessage('The Symfony application requires the primary MySQL installation.');
        $configuration->databaseTargets();
    }

    public function testOfflineCollectorCannotReachMain(): void
    {
        $configuration = $this->config("\$poller_id = 3;\n\$conn_mode = 'offline';\n\$rdatabase_type = 'mysql';\n\$rdatabase_default = 'main';\n\$rdatabase_hostname = 'main.db';\n\$rdatabase_username = 'ru';\n\$rdatabase_password = 'rp';\n");
        self::assertNull($configuration->databaseTargets()['main']);
    }

    public function testPrimaryIsNotARemoteCollector(): void
    {
        self::assertFalse((new CollectorIdentity($this->config('')))->isRemoteCollector());
    }

    public function testCollectorIdZeroIsRefused(): void
    {
        $configuration = $this->config("\$poller_id = 0;\n");
        $this->expectExceptionMessage('Invalid collector identity.');
        $configuration->databaseTargets();
    }

    public function testMissingConfigurationIsRefused(): void
    {
        $this->expectExceptionMessage('Installation configuration is required.');
        (new InstallationConfiguration($this->root))->databaseTargets();
    }

    public function testNonMysqlRemoteDatabaseIsNeverMain(): void
    {
        $remote = "\$poller_id = 3;\n\$rdatabase_type = 'pgsql';\n\$rdatabase_default = 'main';\n\$rdatabase_hostname = 'main.db';\n\$rdatabase_username = 'ru';\n\$rdatabase_password = 'rp';\n";
        self::assertNull($this->config($remote)->databaseTargets()['main']);
        // The web path refuses it outright, before any connection is tried.
        $request = new Request();
        $request->attributes->set('_route', 'inventory_sites');
        $requests = new RequestStack();
        $requests->push($request);
        $this->expectExceptionMessage('Online primary configuration is required for collector Sites administration.');
        (new InstallationConfiguration($this->root, $requests))->values();
    }
}
