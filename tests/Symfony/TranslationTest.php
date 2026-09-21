<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Inventory\Application\Port\SiteEditor;
use Kadupul\Inventory\Domain\Site;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Infrastructure\Symfony\SiteLocaleSubscriber;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class TranslationTest extends TestCase
{
    private function database(array $settings): DatabaseConnection
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        foreach ($settings as $name => $value) {
            $pdo->prepare('INSERT INTO settings VALUES (?, ?)')->execute([$name, $value]);
        }
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        return $database;
    }

    #[DataProvider('preferences')]
    public function testLocalePrecedence(array $settings, ?string $forced, ?string $user, string $browser, string $expected): void
    {
        $preference = $this->createMock(LocalePreference::class);
        $preference->method('preferredLocale')->willReturn($user);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['forced_locale' => $forced]);
        $subscriber = new SiteLocaleSubscriber($preference, $this->database($settings), $configuration);
        $request = Request::create('/inventory/sites', 'GET', ['language' => 'fr', '_locale' => 'fr'], ['Cacti' => 'fixture']);
        $request->attributes->set('_route', 'inventory_sites');
        $request->headers->set('Accept-Language', $browser);
        $subscriber->onRequest(new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame($expected, $request->attributes->get('_locale'));
    }

    public static function preferences(): iterable
    {
        yield 'disabled wins' => [['i18n_language_support' => '0'], 'fr-FR', 'fr', 'fr', 'en'];
        yield 'forced wins' => [[], 'fr_FR', 'en-US', 'en', 'fr'];
        yield 'user wins' => [[], null, 'en-US', 'fr', 'en'];
        yield 'browser weights' => [[], null, null, 'en;q=0.2,fr-FR;q=0.9', 'fr'];
        yield 'unsupported user falls through' => [[], null, '../../secrets', 'fr-CA', 'fr'];
        yield 'default without detection' => [['i18n_auto_detection' => '0', 'i18n_default_language' => 'fr-FR'], null, null, 'en', 'fr'];
        yield 'unsupported browser uses default' => [['i18n_default_language' => 'fr'], null, null, 'ja', 'fr'];
        yield 'English fallback and ignored query' => [[], null, null, 'ja', 'en'];
    }

    #[DataProvider('installationLocales')]
    public function testActualInstallationConfigurationSelectsLocale(string $source, string $expected): void
    {
        $directory = sys_get_temp_dir() . '/kadupul-locale-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        try {
            file_put_contents($directory . '/include/config.php', "<?php\n" . $source);
            $configuration = new InstallationConfiguration($directory);
            $preference = $this->createMock(LocalePreference::class);
            $preference->method('preferredLocale')->willReturn('en-US');
            $subscriber = new SiteLocaleSubscriber($preference, $this->database([]), $configuration);
            $request = Request::create('/inventory/sites', 'GET', [], ['Cacti' => 'fixture']);
            $request->attributes->set('_route', 'inventory_sites');
            $request->headers->set('Accept-Language', 'en-US');
            $subscriber->onRequest(new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
            self::assertSame($expected, $request->attributes->get('_locale'));
            self::assertFalse(defined('CACTI_VERSION'));
        } finally {
            unlink($directory . '/include/config.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }

    public static function installationLocales(): iterable
    {
        yield 'scalar overrides user and browser' => ['$i18n_force_language = "fr-FR";', 'fr'];
        yield 'null preserves preference' => ['$i18n_force_language = null;', 'en'];
        yield 'unset preserves preference' => ['', 'en'];
    }

    public function testFrenchFormEscapesDataTranslatesValidationAndResetsLocale(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['forced_locale' => 'fr-FR']);
            $container->set(LegacyConfiguration::class, $configuration);
            $container->set(DatabaseConnection::class, $this->database([]));
            $actor = new Actor(42, 'operator');
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn($actor);
            $access->method('canManageDevices')->willReturn(true);
            $container->set(ConsoleAccess::class, $access);
            $site = new Site(12, '<script>site</script>', '');
            $editor = $this->createMock(SiteEditor::class);
            $editor->method('find')->willReturn($site);
            $editor->expects(self::never())->method('save');
            $container->set(SiteEditor::class, $editor);
            $path = '/inventory/sites/12/edit';
            $response = $kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('<html lang="fr">', $response->getContent());
            self::assertStringContainsString('Enregistrer le site', $response->getContent());
            self::assertStringContainsString('Modifier &lt;script&gt;site&lt;/script&gt;', $response->getContent());
            self::assertStringContainsString('>Nom</label>', $response->getContent());
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $token = (new \DOMXPath($document))->evaluate('string(//input[@name="site_edit[_token]"]/@value)');
            $request = Request::create($path, 'POST', ['site_edit' => ['name' => '', 'notes' => '', 'revision' => $site->revision(), '_token' => $token]], ['Cacti' => 'fixture']);
            $request->headers->set('Origin', 'http://localhost');
            $response = $kernel->handle($request);
            self::assertSame(422, $response->getStatusCode());
            self::assertStringContainsString('Le nom doit contenir', $response->getContent());
            $response = $kernel->handle(Request::create($path));
            self::assertStringContainsString('<html lang="en">', $response->getContent());
            self::assertStringContainsString('Save site', $response->getContent());
            self::assertFalse(defined('CACTI_VERSION'));
        } finally {
            $kernel->shutdown();
        }
    }
}
