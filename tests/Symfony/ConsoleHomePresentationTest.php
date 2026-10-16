<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Application\Port\ConsoleHomeContent;
use Kadupul\Platform\Application\Port\ProductVersion;
use Kadupul\Platform\Application\ReadModel\ConsoleCollectorStatus;
use Kadupul\Platform\Application\ReadModel\ConsoleCompatibilityContent;
use Kadupul\Platform\Application\ReadModel\ConsoleHomeContent as Content;
use Kadupul\Platform\Application\ReadModel\ProductRelease;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Kadupul\Platform\Infrastructure\Symfony\ConsoleLoginLocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ConsoleHomePresentationTest extends TestCase
{
    private ?string $configurationDirectory = null;

    protected function tearDown(): void
    {
        if ($this->configurationDirectory !== null) {
            unlink($this->configurationDirectory . '/include/config.php');
            rmdir($this->configurationDirectory . '/include');
            rmdir($this->configurationDirectory);
        }
    }

    private function loginLocation(): ConsoleLoginLocation
    {
        $this->configurationDirectory = sys_get_temp_dir() . '/console-login-' . bin2hex(random_bytes(8));
        mkdir($this->configurationDirectory . '/include', 0700, true);
        file_put_contents($this->configurationDirectory . '/include/config.php', '<?php $url_path = "/kadupul/";');

        return new ConsoleLoginLocation(new InstallationConfiguration($this->configurationDirectory));
    }

    public function testAnonymousAndOldLoginPostsOnlyReachTheFixedLoginEntry(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $container->set(ConsoleLoginLocation::class, $this->loginLocation());
            $authenticated = $this->createMock(AuthenticatedAccess::class);
            $authenticated->method('authenticatedActor')->willReturn(null);
            $container->set(AuthenticatedAccess::class, $authenticated);
            $content = $this->createMock(ConsoleHomeContent::class);
            $content->expects(self::never())->method('forActor');
            $container->set(ConsoleHomeContent::class, $content);
            $version = $this->createMock(ProductVersion::class);
            $version->expects(self::never())->method('release');
            $container->set(ProductVersion::class, $version);
            foreach (['GET' => 302, 'POST' => 307] as $method => $status) {
                $response = $kernel->handle(Request::create('/console?return=https://foreign.invalid', $method));
                self::assertSame($status, $response->getStatusCode());
                self::assertSame('/kadupul/login.php', $response->headers->get('Location'));
                self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public static function locales(): iterable
    {
        yield 'French' => ['fr-FR', 'fr', 'Vous êtes maintenant connecté', 'Récupération'];
        yield 'German' => ['de-DE', 'de-DE', 'Geräte erstellen', 'Status des entfernten Datensammlers:'];
    }

    #[DataProvider('locales')]
    public function testTwigEscapesCoreValuesAndRendersTheSelectedLanguageWhileKeepingApprovedExtensionHtml(string $locale, string $htmlLocale, string $introduction, string $status): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $container->set(ConsoleLoginLocation::class, $this->loginLocation());
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['url_path' => '/kadupul/', 'forced_locale' => $locale]);
            $container->set(LegacyConfiguration::class, $configuration);
            $database = $this->createMock(DatabaseConnection::class);
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $database->method('get')->willReturn($pdo);
            $container->set(DatabaseConnection::class, $database);
            $actor = new Actor(7, 'private-account');
            $authenticated = $this->createMock(AuthenticatedAccess::class);
            $authenticated->method('authenticatedActor')->willReturn($actor);
            $container->set(AuthenticatedAccess::class, $authenticated);
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn($actor);
            $container->set(ConsoleAccess::class, $console);
            $nonce = str_repeat('n', 24);
            $policy = "default-src 'self'; script-src 'self' 'nonce-$nonce'";
            $compatibility = new ConsoleCompatibilityContent('<meta name="fixture" content="approved">', '<aside id="before">Before</aside>', '<aside id="after">After</aside>', '<footer>Footer</footer>', ['Content-Security-Policy' => $policy], $nonce);
            $content = $this->createMock(ConsoleHomeContent::class);
            $content->method('forActor')->with($actor)->willReturn(new Content(true, new ConsoleCollectorStatus('recovery', 17), $compatibility));
            $container->set(ConsoleHomeContent::class, $content);
            $version = $this->createMock(ProductVersion::class);
            $version->method('release')->willReturn(new ProductRelease('<script>bad-version</script>', '<beta>'));
            $container->set(ProductVersion::class, $version);
            $response = $kernel->handle(Request::create('/console?_locale=en', 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame($policy, $response->headers->get('Content-Security-Policy'));
            $body = $response->getContent();
            foreach (['<html lang="' . $htmlLocale . '">', $introduction, $status, '&lt;script&gt;bad-version&lt;/script&gt;', '&lt;beta&gt;', 'id="before"', 'id="after"', 'Footer'] as $text) {
                self::assertStringContainsString($text, $body);
            }
            $document = new \DOMDocument();
            @$document->loadHTML($body, LIBXML_NONET);
            self::assertSame(1, (new \DOMXPath($document))->query("//a[@href='/kadupul/host.php']")->length);
            self::assertStringNotContainsString('<script>bad-version</script>', $body);
            self::assertStringNotContainsString('private-account', $body);
            self::assertLessThan(strpos($body, 'console-welcome'), strpos($body, 'id="before"'));
            self::assertGreaterThan(strpos($body, 'console-welcome'), strpos($body, 'id="after"'));
        } finally {
            $kernel->shutdown();
        }
    }
}
