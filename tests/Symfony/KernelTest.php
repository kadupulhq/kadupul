<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpFoundation\Request;

final class KernelTest extends TestCase
{
    public static function environments(): iterable
    {
        yield ['test', true];
        yield ['prod', false];
    }

    #[DataProvider('environments')]
    public function testLivenessWithoutLegacyBootstrap(string $environment, bool $debug): void
    {
        $kernel = new Kernel($environment, $debug);
        try {
            $request = Request::create('/healthz');
            $response = $kernel->handle($request);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(['status' => 'ok'], json_decode($response->getContent(), true));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertSame([], $response->headers->getCookies());
            self::assertFalse(defined('CACTI_VERSION'));
            $kernel->terminate($request, $response);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testLegacyAndPrivatePathsAreNotRouted(): void
    {
        $kernel = new Kernel('test', false);
        try {
            foreach (['/', '/host.php', '/auth_login.php', '/include/config.php', '/.env', '/config/services.yaml'] as $path) {
                self::assertSame(404, $kernel->handle(Request::create($path))->getStatusCode(), $path);
            }
            self::assertSame(405, $kernel->handle(Request::create('/healthz', 'POST'))->getStatusCode());
            self::assertSame('', $kernel->handle(Request::create('/healthz', 'HEAD'))->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testConsoleAndTwigBootWithoutDatabase(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $application = new Application($kernel);
            $application->setAutoExit(false);
            $tester = new ApplicationTester($application);
            self::assertSame(0, $tester->run(['command' => 'about']));
            self::assertStringContainsString('7.4.', $tester->getDisplay());
            $twig = $kernel->getContainer()->get('test.service_container')->get('twig');
            self::assertStringContainsString('<title>Kadupul</title>', $twig->render('base.html.twig'));
            self::assertSame('&lt;script&gt;', $twig->createTemplate('{{ value }}')->render(['value' => '<script>']));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testIdentityRejectsUntrustedCookiesWithoutLegacyBootstrap(): void
    {
        $kernel = new Kernel('test', false);
        try {
            $request = Request::create('/session', 'GET', [], ['Cacti' => 'untrusted-cookie']);
            $request->headers->set('X-Remote-User', 'admin');
            $response = $kernel->handle($request);
            self::assertSame(401, $response->getStatusCode());
            self::assertSame(['error' => 'authentication_required'], json_decode($response->getContent(), true));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertSame([], $response->headers->getCookies());
            self::assertSame(405, $kernel->handle(Request::create('/session', 'POST'))->getStatusCode());
            self::assertSame('', $kernel->handle(Request::create('/session', 'HEAD'))->getContent());
            self::assertFalse(defined('CACTI_VERSION'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testInventoryFailsClosedWithoutLegacyBootstrap(): void
    {
        $kernel = new Kernel('test', true);
        try {
            foreach (['/inventory/devices', '/inventory/devices.json', '/inventory/devices.csv'] as $path) {
                self::assertSame(401, $kernel->handle(Request::create($path))->getStatusCode());
                self::assertSame(405, $kernel->handle(Request::create($path, 'POST'))->getStatusCode());
            }
            self::assertSame(401, $kernel->handle(Request::create('/inventory/devices/1/edit'))->getStatusCode());
            self::assertSame(400, $kernel->handle(Request::create('/inventory/devices?page[]=1'))->getStatusCode());
            self::assertFalse(defined('CACTI_VERSION'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testDeviceFormsRejectAnonymousRequestsBeforeLoadingInstallationConfiguration(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $configuration = $this->createMock(\Kadupul\Platform\Contract\LegacyConfiguration::class);
            $configuration->expects(self::never())->method('values');
            $kernel->getContainer()->get('test.service_container')->set(\Kadupul\Platform\Contract\LegacyConfiguration::class, $configuration);
            foreach (['/inventory/devices/1/edit', '/inventory/devices/new'] as $path) {
                $response = $kernel->handle(Request::create($path));
                self::assertSame(401, $response->getStatusCode());
                self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testProductionDeviceEditRouteRejectsAnonymousRequestWithoutInstallationConfiguration(): void
    {
        $kernel = new Kernel('prod', false);
        try {
            $response = $kernel->handle(Request::create('/inventory/devices/1/edit'));
            self::assertSame(401, $response->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }
}
