<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\Platform\Application\Port\ProductVersion;
use Kadupul\Platform\Application\ReadModel\ProductRelease;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AboutPresentationTest extends TestCase
{
    public function testAnonymousRequestsNeverReadVersion(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $access = $this->createMock(AuthenticatedAccess::class);
            $access->method('authenticatedActor')->willReturn(null);
            $container->set(AuthenticatedAccess::class, $access);
            $version = $this->createMock(ProductVersion::class);
            $version->expects(self::never())->method('release');
            $container->set(ProductVersion::class, $version);
            foreach (['/about', '/about/legacy'] as $path) {
                $response = $kernel->handle(Request::create($path . '?_locale[]=fr&version[]=x'));
                self::assertSame(401, $response->getStatusCode());
                self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
                self::assertStringNotContainsString('Version', $response->getContent());
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testEscapedVersionFullLicenseAndFrenchLocaleWithoutActorDisclosure(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['forced_locale' => 'fr-FR']);
            $container->set(LegacyConfiguration::class, $configuration);
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $container->set(DatabaseConnection::class, $database);
            $access = $this->createMock(AuthenticatedAccess::class);
            $access->method('authenticatedActor')->willReturn(new Actor(7, 'private-username'));
            $container->set(AuthenticatedAccess::class, $access);
            $version = $this->createMock(ProductVersion::class);
            $version->method('release')->willReturn(new ProductRelease('<script>version</script>', '<beta>'));
            $container->set(ProductVersion::class, $version);
            $response = $kernel->handle(Request::create('/about?_locale=en', 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame("default-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'", $response->headers->get('Content-Security-Policy'));
            self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
            $body = $response->getContent();
            foreach (['<html lang="fr">', 'À propos de Kadupul', 'Version &lt;script&gt;version&lt;/script&gt;', '- Bêta &lt;beta&gt;', 'version 2', 'toute version ultérieure', 'SANS AUCUNE GARANTIE', 'QUALITÉ MARCHANDE', 'ADÉQUATION À UN USAGE PARTICULIER', 'https://github.com/kadupulhq/kadupul'] as $text) {
                self::assertStringContainsString($text, $body);
            }
            self::assertStringNotContainsString('private-username', $body);
            self::assertStringNotContainsString('<script>', $body);
            $response = $kernel->handle(Request::create('/about'));
            self::assertStringContainsString('WITHOUT ANY WARRANTY', $response->getContent());
            self::assertStringContainsString('either version 2 of the License, or (at your option) any later version.', $response->getContent());
            $response = $kernel->handle(Request::create('/about/legacy'));
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/about', $response->headers->get('Location'));
            self::assertSame("default-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'", $response->headers->get('Content-Security-Policy'));
            self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
            self::assertSame(405, $kernel->handle(Request::create('/about/legacy', 'POST'))->getStatusCode());
            self::assertSame(405, $kernel->handle(Request::create('/about', 'POST'))->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }
}
