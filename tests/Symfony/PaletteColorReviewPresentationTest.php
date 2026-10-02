<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Application\Port\PaletteColorPreferences;
use Kadupul\Graphing\Application\Port\PaletteColorStore;
use Kadupul\Graphing\Application\Query\PaletteColorAccessDenied;
use Kadupul\Graphing\Domain\PaletteColor;
use Kadupul\Graphing\Domain\PaletteColorFilters;
use Kadupul\Graphing\Domain\PaletteColorPage;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PaletteColorReviewPresentationTest extends TestCase
{
    public function testUnnamedColorHasAVisibleAndAccessibleHexFallback(): void
    {
        $kernel = $this->kernel();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            $store = $this->createMock(PaletteColorStore::class);
            $store->method('defaultRows')->willReturn(25);
            $store->method('defaultHasGraphs')->willReturn(false);
            $store->method('list')->willReturnCallback(static fn(PaletteColorFilters $filters): PaletteColorPage => new PaletteColorPage([
                new PaletteColor(7, '', 'AbC', false), new PaletteColor(8, '0', '123', false),
                new PaletteColor(9, " \t\n", '456', false),
            ], 3, $filters));
            $container->set(PaletteColorStore::class, $store);
            $container->set(PaletteColorPreferences::class, $this->createMock(PaletteColorPreferences::class));
            $response = $kernel->handle(Request::create('/graphing/colors?named=false'));
            self::assertSame(200, $response->getStatusCode());
            $xpath = $this->xpath($response->getContent());
            self::assertSame('AbC', $xpath->evaluate('string(//tbody/tr[1]/td[2]/a)'));
            self::assertSame('Select AbC', $xpath->evaluate('string(//input[@name="ids[]" and @value="7"]/@aria-label)'));
            self::assertSame('0', $xpath->evaluate('string(//tbody/tr[2]/td[2]/a)'));
            self::assertSame('Select 0', $xpath->evaluate('string(//input[@name="ids[]" and @value="8"]/@aria-label)'));
            self::assertSame('456', $xpath->evaluate('string(//tbody/tr[3]/td[2]/a)'));
            self::assertSame('Select 456', $xpath->evaluate('string(//input[@name="ids[]" and @value="9"]/@aria-label)'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testBareLegacyMenuRedirectDoesNotReadCatalogOrOverwriteRememberedFilters(): void
    {
        $kernel = $this->kernel();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            $store = $this->createMock(PaletteColorStore::class);
            foreach (['defaultRows', 'defaultHasGraphs', 'list'] as $method) $store->expects(self::never())->method($method);
            $container->set(PaletteColorStore::class, $store);
            $response = $kernel->handle(Request::create('/graphing/colors/legacy'));
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/graphing/colors', $response->headers->get('Location'));
            $cleared = $kernel->handle(Request::create('/graphing/colors/legacy?clear=1&header=false'));
            self::assertSame(302, $cleared->getStatusCode());
            self::assertSame('/graphing/colors?reset=1', $cleared->headers->get('Location'));
            self::assertSame(400, $kernel->handle(Request::create('/graphing/colors/legacy?clear[x]=1'))->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testRemoteCollectorRendersExplicitFiltersWithoutWritingPrimaryPreferences(): void
    {
        $kernel = $this->kernel(collector: 2);
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            $store = $this->createMock(PaletteColorStore::class);
            $store->method('defaultRows')->willReturn(25);
            $store->method('defaultHasGraphs')->willReturn(false);
            $store->expects(self::once())->method('list')->willReturnCallback(static function (PaletteColorFilters $filters): PaletteColorPage {
                self::assertSame('remote', $filters->query()['filter']);
                return new PaletteColorPage([], 0, $filters);
            });
            $container->set(PaletteColorStore::class, $store);
            $preferences = $this->createMock(PaletteColorPreferences::class);
            $preferences->expects(self::never())->method('save');
            $container->set(PaletteColorPreferences::class, $preferences);
            self::assertSame(200, $kernel->handle(Request::create('/graphing/colors?filter=remote&named=false'))->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testDeleteGetFormCarriesOriginatingFiltersAsSuccessfulControls(): void
    {
        $kernel = $this->kernel();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            $store = $this->createMock(PaletteColorStore::class);
            $store->method('defaultRows')->willReturn(25);
            $store->method('defaultHasGraphs')->willReturn(false);
            $store->method('list')->willReturnCallback(static fn(PaletteColorFilters $filters): PaletteColorPage => new PaletteColorPage([new PaletteColor(7, 'same', '123', false)], 26, $filters));
            $container->set(PaletteColorStore::class, $store);
            $container->set(PaletteColorPreferences::class, $this->createMock(PaletteColorPreferences::class));
            $filters = ['filter' => '東京 & x', 'rows' => '25', 'page' => '2', 'sort_column' => 'hex', 'sort_direction' => 'DESC', 'has_graphs' => 'false', 'named' => 'false'];
            $response = $kernel->handle(Request::create('/graphing/colors?' . http_build_query($filters)));
            self::assertSame(200, $response->getStatusCode());
            $xpath = $this->xpath($response->getContent());
            foreach ($filters as $key => $value) {
                self::assertSame($value, $xpath->evaluate('string(//form[@aria-describedby="palette-selection-help"]/input[@type="hidden" and @name="' . $key . '"]/@value)'), $key);
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testDeletionConfirmationIdentifiesUnnamedAndDuplicateNamedColorsByHex(): void
    {
        $kernel = $this->kernel();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            $store = $this->createMock(PaletteColorStore::class);
            $store->method('findMany')->willReturn([new PaletteColor(7, '', '123', false), new PaletteColor(8, " \t", '456', false), new PaletteColor(9, 'same', '789', false), new PaletteColor(10, 'same', 'abc', false)]);
            $container->set(PaletteColorStore::class, $store);
            $response = $kernel->handle(Request::create('/graphing/colors/actions/delete?ids[]=7&ids[]=8&ids[]=9&ids[]=10'));
            self::assertSame(200, $response->getStatusCode());
            $xpath = $this->xpath($response->getContent());
            foreach ([1 => '123', 2 => '456', 3 => '789', 4 => 'abc'] as $index => $hex) {
                $text = $xpath->evaluate('string(//main/ul/li[' . $index . '])');
                self::assertStringContainsString($hex, $text);
                if ($index > 2) self::assertStringContainsString('same', $text);
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testKnownDuplicateHexIsRenderedAsFrenchValidationWithSameOriginCsrf(): void
    {
        $kernel = $this->kernel(locale: 'fr');
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            $store = $this->createMock(PaletteColorStore::class);
            $store->expects(self::once())->method('save')->with(42, null, 'duplicate', 'AbC', '')
                ->willThrowException(new \InvalidArgumentException('A Color with this hex value already exists.'));
            $container->set(PaletteColorStore::class, $store);
            $get = Request::create('/graphing/colors/new', 'GET', [], ['Cacti' => 'fixture']);
            $get->setLocale('fr');
            $form = $kernel->handle($get);
            self::assertSame(200, $form->getStatusCode());
            $token = $this->xpath($form->getContent())->evaluate('string(//input[@name="palette_color[_token]"]/@value)');
            self::assertNotSame('', $token);
            $post = Request::create('/graphing/colors/new', 'POST', ['palette_color' => [
                'name' => 'duplicate', 'hex' => 'AbC', 'revision' => '', '_token' => $token,
            ]], ['Cacti' => 'fixture']);
            $post->headers->set('Origin', 'http://localhost');
            $post->setLocale('fr');
            $response = $kernel->handle($post);
            self::assertSame(422, $response->getStatusCode());
            self::assertStringContainsString('Une couleur avec cette valeur hexadécimale existe déjà.', $response->getContent());
            self::assertStringNotContainsString('incertain', $response->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    #[DataProvider('deniedRoutes')]
    public function testConsoleOnlyActorIsRejectedBeforeParsingOrStoreHandoff(string $path, string $method): void
    {
        $kernel = $this->kernel(false);
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            $store = $this->createMock(PaletteColorStore::class);
            foreach (['defaultRows', 'defaultHasGraphs', 'find', 'findMany', 'list', 'save', 'delete', 'snapshot', 'export', 'import'] as $call) {
                $store->expects(self::never())->method($call);
            }
            $container->set(PaletteColorStore::class, $store);
            $response = $kernel->handle(Request::create($path, $method));
            self::assertSame(403, $response->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }

    public static function deniedRoutes(): array
    {
        return [
            ['/graphing/colors?filter[]=bad', 'GET'], ['/graphing/colors/new?rows[]=bad', 'GET'],
            ['/graphing/colors/7/edit?rows[]=bad', 'GET'], ['/graphing/colors/actions/delete?ids[]=bad', 'GET'],
            ['/graphing/colors/legacy?action[]=bad', 'GET'], ['/graphing/colors/import?bad[]=x', 'GET'],
            ['/graphing/colors/export?filter[]=bad', 'GET'], ['/graphing/colors/new', 'POST'],
            ['/graphing/colors/7/edit', 'POST'], ['/graphing/colors/actions/delete', 'POST'],
            ['/graphing/colors/import', 'POST'], ['/graphing/colors/legacy', 'POST'],
        ];
    }

    private function kernel(bool $allowed = true, string $locale = 'en', int $collector = 1): Kernel
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $container->set(DatabaseConnection::class, $database);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['forced_locale' => $locale, 'collector_id' => $collector]);
        $container->set(LegacyConfiguration::class, $configuration);
        $actor = new Actor(42, 'console-only');
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn($actor);
        $container->set(ConsoleAccess::class, $console);
        $access = $this->createMock(PaletteColorAccess::class);
        if ($allowed) {
            $access->method('authorize')->willReturn($actor);
        } else {
            $access->method('authorize')->willThrowException(new PaletteColorAccessDenied(false));
        }
        $container->set(PaletteColorAccess::class, $access);
        return $kernel;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        return new \DOMXPath($document);
    }
}
