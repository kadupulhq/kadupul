<?php

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

    private function kernel(bool $allowed = true, string $locale = 'en'): Kernel
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
        $configuration->method('values')->willReturn(['forced_locale' => $locale]);
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
