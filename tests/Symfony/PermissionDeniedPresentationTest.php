<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Application\Port\PermissionDeniedContent;
use Kadupul\Platform\Application\ReadModel\PermissionDenied;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\ConsoleUpgradeRequired;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Kadupul\Platform\Infrastructure\Symfony\ConsoleLoginLocation;
use Kadupul\Platform\Infrastructure\Symfony\LegacyPageForwarder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PermissionDeniedPresentationTest extends TestCase
{
    private const string POLICY = "default-src 'self'; script-src 'self' 'nonce-nnnnnnnnnnnnnnnnnnnnnnnn'";

    private ?string $configurationDirectory = null;

    protected function tearDown(): void
    {
        if ($this->configurationDirectory !== null) {
            unlink($this->configurationDirectory . '/include/config.php');
            rmdir($this->configurationDirectory . '/include');
            rmdir($this->configurationDirectory);
        }
    }

    /** @param \Closure(Actor): PermissionDenied|null $content null means it must never be read */
    private function handle(?Actor $actor, ?\Closure $content, Request $request, string $locale = 'en-US'): Response
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $this->configurationDirectory = sys_get_temp_dir() . '/permission-denied-' . bin2hex(random_bytes(8));
            mkdir($this->configurationDirectory . '/include', 0700, true);
            file_put_contents($this->configurationDirectory . '/include/config.php', '<?php $url_path = "/kadupul/";');
            $container->set(ConsoleLoginLocation::class, new ConsoleLoginLocation(new InstallationConfiguration($this->configurationDirectory)));
            $configuration = self::createStub(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['url_path' => '/kadupul/', 'forced_locale' => $locale]);
            $container->set(LegacyConfiguration::class, $configuration);
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $database = self::createStub(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $container->set(DatabaseConnection::class, $database);
            $access = self::createStub(AuthenticatedAccess::class);
            $access->method('authenticatedActor')->willReturn($actor);
            $container->set(AuthenticatedAccess::class, $access);
            $port = $this->createMock(PermissionDeniedContent::class);
            if ($content === null) {
                $port->expects(self::never())->method('forActor');
            } else {
                $port->method('forActor')->willReturnCallback($content);
            }
            $container->set(PermissionDeniedContent::class, $port);

            return $kernel->handle($request);
        } finally {
            $kernel->shutdown();
        }
    }

    private static function page(?string $returnTo): \Closure
    {
        return static fn(Actor $actor): PermissionDenied => new PermissionDenied($returnTo, false, '<script>1.3.0</script>', '(c) 2004-2026 - The Cacti Group', null, ['Content-Security-Policy' => self::POLICY]);
    }

    private static function xpath(Response $response): \DOMXPath
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML((string) $response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));

        return new \DOMXPath($document);
    }

    public function testAnonymousRequestsGoToTheInstallationLoginWithoutRunningExtensions(): void
    {
        $response = $this->handle(null, null, Request::create('/permission-denied', 'GET', [], [], [], ['HTTP_REFERER' => 'https://foreign.invalid/']));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/kadupul/login.php', $response->headers->get('Location'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function testValidatedRefererBecomesTheReturnLinkBelowTheInstallation(): void
    {
        $response = $this->handle(new Actor(7, 'viewer'), self::page('/kadupul/host.php?id=3&action=edit'), Request::create('/permission-denied', 'GET', [], ['Cacti' => 'fixture']));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        // Core page: Symfony's strict policy, not the native one passed for extensions.
        self::assertStringNotContainsString('nonce-', $response->headers->get('Content-Security-Policy'));
        $xpath = self::xpath($response);
        self::assertSame('Permission Denied', $xpath->evaluate('string(//title)'));
        self::assertSame(1, $xpath->query('//body[@class="logoutBody"]//h1[@class="loginHeading" and .="Permission Denied"]')->length);
        self::assertSame(1, $xpath->query('//a[@href="/kadupul/host.php?id=3&action=edit" and .="Return"]')->length);
        self::assertSame(0, $xpath->query('//a[contains(@class, "returnTo")]')->length);
        self::assertSame(1, $xpath->query('//a[@href="/kadupul/logout.php" and .="Login Again"]')->length);
        $scripts = $xpath->query('//script[@src and not(@nonce)]');
        self::assertSame(1, $scripts->length);
        // The source path, or its AssetMapper copy when public/assets is compiled, as CI does.
        self::assertMatchesRegularExpression('~\A/kadupul/(?:include/js/permission-denied\.js\?[0-9a-f]{32}|public/assets/include/js/permission-denied-[A-Za-z0-9_-]+\.js)\z~', $scripts->item(0)->getAttribute('src'));
        self::assertSame(0, $xpath->query('//script[not(@src)]')->length);
        self::assertSame('Version <script>1.3.0</script> | (c) 2004-2026 - The Cacti Group', $xpath->evaluate('string(//div[@class="versionInfo"])'));
        self::assertStringNotContainsString('<script>1.3.0', (string) $response->getContent());
        foreach (['You are not permitted to access this section of Kadupul.', 'If you feel that this is an error. Please contact your Kadupul Administrator.'] as $text) {
            self::assertSame(1, $xpath->query('//div[@class="logoutTitle"]/p[.="' . $text . '"]')->length);
        }
    }

    public function testRefusedRefererFallsBackToTheConsoleBelowTheInstallation(): void
    {
        // validate_redirect_url() answers its relative default for a foreign or malformed Referer.
        $xpath = self::xpath($this->handle(new Actor(7, 'viewer'), self::page('index.php'), Request::create('/permission-denied')));
        self::assertSame(1, $xpath->query('//a[@href="/kadupul/index.php" and .="Return"]')->length);
    }

    public function testWithoutARefererReturnStepsBackInHistory(): void
    {
        $xpath = self::xpath($this->handle(new Actor(7, 'viewer'), self::page(null), Request::create('/permission-denied')));
        self::assertSame(1, $xpath->query('//a[@class="returnTo" and @href="#" and .="Return"]')->length);
        self::assertSame(1, $xpath->query('//a[@href="/kadupul/logout.php"]')->length);
    }

    public function testReturnLocationIsEscapedForTheAttribute(): void
    {
        $response = $this->handle(new Actor(7, 'viewer'), self::page('/kadupul/graph_view.php?a=1&b="x" onclick=y'), Request::create('/permission-denied'));
        self::assertStringContainsString('href="/kadupul/graph_view.php?a=1&amp;b=&quot;x&quot; onclick=y"', (string) $response->getContent());
        self::assertSame(0, self::xpath($response)->query('//a[@onclick]')->length);
    }

    public function testFrenchUsesTheExistingGettextTranslations(): void
    {
        $xpath = self::xpath($this->handle(new Actor(7, 'viewer'), self::page(null), Request::create('/permission-denied', 'GET', [], ['Cacti' => 'fixture']), 'fr-FR'));
        self::assertSame('fr', $xpath->evaluate('string(/html/@lang)'));
        self::assertSame('Permission refusée', $xpath->evaluate('string(//h1)'));
        self::assertSame(1, $xpath->query('//a[.="Retour"]')->length);
        self::assertSame(1, $xpath->query('//a[.="Se connecter de nouveau"]')->length);
        self::assertSame(1, $xpath->query('//p[.="Vous n\'êtes pas autorisé à accéder à cette section de Kadupul."]')->length);
    }

    public function testCustomDeniedReskinReplacesThePageAndKeepsItsNativePolicy(): void
    {
        $html = '<!DOCTYPE html><title>Custom</title><script nonce="nnnnnnnnnnnnnnnnnnnnnnnn">custom()</script>';
        $content = static fn(Actor $actor): PermissionDenied => new PermissionDenied(null, true, '1.3.0', '(c)', $html, ['Content-Security-Policy' => self::POLICY]);
        $response = $this->handle(new Actor(7, 'viewer'), $content, Request::create('/permission-denied', 'GET', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
        // A reskin wins over the AJAX text, as the hook ran first natively.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($html, $response->getContent());
        self::assertSame(self::POLICY, $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function testAjaxRequestsReceiveTheNativeTextAndStatus(): void
    {
        $content = static fn(Actor $actor): PermissionDenied => new PermissionDenied('/kadupul/host.php', true, '1.3.0', '(c)');
        $response = $this->handle(new Actor(7, 'viewer'), $content, Request::create('/permission-denied', 'GET', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'xmlhttprequest']));
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('401 Permission Denied', (string) $response);
        self::assertSame('You are not permitted to access this section of Kadupul.  If you feel that this is an error. Please contact your Kadupul Administrator.', $response->getContent());
        self::assertStringNotContainsString('<', (string) $response->getContent());
    }

    public function testSchemaMismatchKeepsTheNativeInstallerRedirect(): void
    {
        $content = static fn(Actor $actor): PermissionDenied => throw new ConsoleUpgradeRequired('/kadupul/install/');
        $response = $this->handle(new Actor(7, 'viewer'), $content, Request::create('/permission-denied'));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/kadupul/install/', $response->headers->get('Location'));
    }

    public function testWorkerFailureIsUnavailableWithoutDiagnostics(): void
    {
        $content = static fn(Actor $actor): PermissionDenied => throw new \RuntimeException('secret worker detail');
        $response = $this->handle(new Actor(7, 'viewer'), $content, Request::create('/permission-denied'));
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('secret', (string) $response->getContent());
    }

    public function testCompatibilityEntryRendersInPlaceForASubdirectoryInstall(): void
    {
        $original = Request::create('http://example.test/kadupul/permission_denied.php', 'GET', [], [], [], [
            'SCRIPT_NAME' => '/kadupul/permission_denied.php', 'SCRIPT_FILENAME' => '/srv/kadupul/permission_denied.php',
            'HTTP_REFERER' => 'http://example.test/kadupul/graph_view.php',
        ]);
        $request = LegacyPageForwarder::request($original, '/srv/kadupul', '/permission-denied');
        self::assertSame('/permission-denied', $request->getPathInfo());
        self::assertSame('http://example.test/kadupul/graph_view.php', $request->headers->get('Referer'));
        $response = $this->handle(new Actor(7, 'viewer'), self::page('/kadupul/graph_view.php'), $request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, self::xpath($response)->query('//a[@href="/kadupul/graph_view.php"]')->length);
    }
}
