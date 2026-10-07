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
use Kadupul\IdentityAccess\Contract\RealmGrants;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Asset\CompiledAssetManifest;
use Kadupul\Platform\Infrastructure\Asset\CompiledAssetVersionStrategy;
use Kadupul\Platform\Infrastructure\Symfony\ThemeStylesheet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Context\RequestStackContext;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class LayoutRenderingTest extends TestCase
{
    public function testConsolePageCarriesThemeMenuAndLegacyHooks(): void
    {
        $request = self::request('/inventory/devices/new');
        $session = new Session(new MockArraySessionStorage());
        $session->getFlashBag()->add('notice', '<b>Saved</b>');
        $request->setSession($session);
        $xpath = self::render(new Actor(7, 'operator'), [3, 14], 'paper-plane', $request);

        $stylesheet = $xpath->query('//head/link[@rel="stylesheet"]')->item(0);
        self::assertNotNull($stylesheet);
        self::assertMatchesRegularExpression('~\A/kadupul/(?:include/themes/paper-plane/main\.css\?[0-9a-f]{32}|public/assets/include/themes/paper-plane/main-[A-Za-z0-9_-]+\.css)\z~', $stylesheet->getAttribute('href'));
        foreach (['cactiPageHead', 'tabs', 'cactiContent', 'navigation', 'navigation_right'] as $id) {
            self::assertSame(1, $xpath->query('//*[@id="' . $id . '"]')->length, $id);
        }
        self::assertSame(0, $xpath->query('//*[@id="main" or @id="nav"]')->length, 'themes hide #main and #nav submenus until legacy JavaScript runs');
        self::assertSame(1, $xpath->query('//nav[@id="navigation" and contains(@class, "cactiConsoleNavigationArea")]')->length);
        self::assertSame(1, $xpath->query('//div[@id="navigation_right" and contains(@class, "cactiConsoleContentArea")]/main')->length);
        self::assertSame(['Main Console', 'Create', 'Management', 'Data Collection', 'Presets'], self::texts($xpath, '//li[@class="menuitem"]/span[@class="menu_parent"]'));
        self::assertSame(
            ['/kadupul/index.php', '/kadupul/app.php/inventory/devices/new', '/kadupul/host.php', '/kadupul/sites.php', '/kadupul/data_sources.php', '/kadupul/pollers.php', '/kadupul/cdef.php', '/kadupul/vdef.php', '/kadupul/about.php'],
            array_map(static fn(\DOMElement $link): string => $link->getAttribute('href'), iterator_to_array($xpath->query('//nav[@id="navigation"]//a'))),
        );
        $current = $xpath->query('//a[@aria-current="page"]');
        self::assertSame(1, $current->length);
        self::assertSame('New Device', $current->item(0)->textContent);
        self::assertSame('pic selected', $current->item(0)->getAttribute('class'));
        self::assertSame(['<b>Saved</b>'], self::texts($xpath, '//*[@role="status"]/p[@class="flash-notice"]'));
        self::assertSame(0, $xpath->query('//b')->length);
        self::assertSame(0, $xpath->query('//script')->length);
    }

    public function testAccountWithoutConsoleRealmGetsThemeButNoMenu(): void
    {
        $xpath = self::render(null, [3], 'modern', self::request('/about'));

        self::assertSame(1, $xpath->query('//head/link[@rel="stylesheet"]')->length);
        self::assertSame(0, $xpath->query('//*[@id="navigation"]')->length);
        self::assertSame(0, $xpath->query('//*[@role="status"]')->length);
        self::assertSame(1, $xpath->query('//*[@id="navigation_right"]/main')->length);
    }

    public function testThemeFollowsUserThenSystemSettingAndInstalledDirectories(): void
    {
        $root = self::installation();
        try {
            $actor = new Actor(7, 'operator');
            self::assertSame('include/themes/dark/main.css', self::theme($root, $actor, 'dark', 'modern')->path());
            self::assertSame('include/themes/modern/main.css', self::theme($root, $actor, '../../etc', 'modern')->path());
            self::assertSame('include/themes/dark/main.css', self::theme($root, $actor, '', 'dark')->path());
            self::assertSame('include/themes/modern/main.css', self::theme($root, $actor, 'missing', 'missing')->path());
            self::assertSame('include/themes/modern/main.css', self::theme($root, null, 'dark', 'dark')->path());
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($root);
        }
    }

    /**
     * AssetMapper's default package emits a digested /assets/ URL even before
     * asset-map:compile has run, and nothing serves that URL. The legacy
     * package keeps the documented compiled-or-?md5 contract.
     */
    public function testLegacyAssetPackageUsesCompiledManifestThenUncompiledFallback(): void
    {
        $root = self::installation();
        $requests = new RequestStack();
        $requests->push(self::request('/graph-definitions/vdefs'));
        $package = static fn(): PathPackage => new PathPackage('/', new CompiledAssetVersionStrategy(
            new CompiledAssetManifest($root . '/public/assets/manifest.json', 'public'),
            $root,
        ), new RequestStackContext($requests));
        try {
            self::assertSame('/kadupul/include/themes/dark/main.css?' . md5('body{}dark'), $package()->getUrl('include/themes/dark/main.css'));
            self::assertSame('/kadupul/include/themes/absent/main.css', $package()->getUrl('include/themes/absent/main.css'));
            self::assertSame('/kadupul/include/../include/themes/dark/main.css', $package()->getUrl('include/../include/themes/dark/main.css'));

            mkdir($root . '/public/assets', 0700, true);
            file_put_contents($root . '/public/assets/manifest.json', json_encode(['include/themes/dark/main.css' => '/assets/include/themes/dark/main-3f9a.css']));
            self::assertSame('/kadupul/public/assets/include/themes/dark/main-3f9a.css', $package()->getUrl('include/themes/dark/main.css'));
            self::assertSame('/kadupul/include/themes/modern/main.css?' . md5('body{}modern'), $package()->getUrl('include/themes/modern/main.css'));
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($root);
        }
    }

    public function testKernelAssetPackagesDifferOnUncompiledInstallations(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $container->get('request_stack')->push(self::request('/graph-definitions/vdefs'));
            if (is_file($kernel->getProjectDir() . '/public/assets/manifest.json')) {
                self::markTestSkipped('This checkout has compiled assets.');
            }
            $twig = $container->get('twig');
            self::assertMatchesRegularExpression('~\A/kadupul/assets/include/themes/modern/main-[A-Za-z0-9_-]+\.css\z~', $twig->createTemplate("{{ asset('include/themes/modern/main.css') }}")->render());
            self::assertSame(
                '/kadupul/include/themes/modern/main.css?' . md5_file($kernel->getProjectDir() . '/include/themes/modern/main.css'),
                $twig->createTemplate("{{ asset('include/themes/modern/main.css', 'legacy') }}")->render(),
            );
        } finally {
            $kernel->shutdown();
        }
    }

    /** @param list<int> $realms */
    private static function render(?Actor $consoleActor, array $realms, string $theme, Request $request): \DOMXPath
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $session = self::session($consoleActor, $realms);
            $container->set(ConsoleAccess::class, $session);
            $container->set(AuthenticatedAccess::class, $session);
            $container->set(DatabaseConnection::class, self::database($theme, 'modern'));
            $configuration = self::createStub(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['collector_id' => 1]);
            $container->set(LegacyConfiguration::class, $configuration);
            $container->get('request_stack')->push($request);
            $html = $container->get('twig')->render('graph_definition/vdefs.html.twig', [
                'rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'criteria' => new \Kadupul\GraphDefinition\Domain\VdefListCriteria(),
            ]);
        } finally {
            $kernel->shutdown();
        }
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING));

        return new \DOMXPath($document);
    }

    private static function request(string $route): Request
    {
        return Request::create('http://localhost/kadupul/app.php' . $route, 'GET', [], [], [], [
            'SCRIPT_NAME' => '/kadupul/app.php',
            'SCRIPT_FILENAME' => dirname(__DIR__, 2) . '/app.php',
        ]);
    }

    /** The console actor and its realm grants come from one adapter, as in production. */
    private static function session(?Actor $consoleActor, array $realms): object
    {
        return new class ($consoleActor, $realms) implements ConsoleAccess, AuthenticatedAccess, RealmGrants {
            public function __construct(private ?Actor $consoleActor, private array $realms) {}

            public function consoleActor(): ?Actor
            {
                return $this->consoleActor;
            }

            public function authenticatedActor(): ?Actor
            {
                return new Actor(7, 'operator');
            }

            public function canManageDevices(Actor $actor): bool
            {
                return false;
            }

            public function grantedRealms(Actor $actor, array $realmIds): array
            {
                return array_values(array_intersect($realmIds, [8, ...$this->realms]));
            }
        };
    }

    private static function theme(string $root, ?Actor $actor, string $userTheme, string $systemTheme): ThemeStylesheet
    {
        $access = new class ($actor) implements AuthenticatedAccess {
            public function __construct(private ?Actor $actor) {}

            public function authenticatedActor(): ?Actor
            {
                return $this->actor;
            }
        };

        return new ThemeStylesheet($access, self::database($userTheme, $systemTheme), $root);
    }

    private static function installation(): string
    {
        $root = sys_get_temp_dir() . '/kadupul-theme-' . bin2hex(random_bytes(6));
        foreach (['modern', 'dark'] as $theme) {
            mkdir($root . '/include/themes/' . $theme, 0700, true);
            file_put_contents($root . '/include/themes/' . $theme . '/main.css', 'body{}' . $theme);
        }

        return $root;
    }

    private static function database(string $userTheme, string $systemTheme): DatabaseConnection
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $pdo->exec('CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT)');
        $pdo->prepare("INSERT INTO settings VALUES ('selected_theme', ?)")->execute([$systemTheme]);
        $pdo->prepare("INSERT INTO settings_user VALUES (7, 'selected_theme', ?)")->execute([$userTheme]);

        return new class ($pdo) implements DatabaseConnection {
            public function __construct(private \PDO $pdo) {}

            public function get(): \PDO
            {
                return $this->pdo;
            }
        };
    }

    /** @return list<string> */
    private static function texts(\DOMXPath $xpath, string $query): array
    {
        return array_map(static fn(\DOMNode $node): string => $node->textContent, iterator_to_array($xpath->query($query)));
    }
}
