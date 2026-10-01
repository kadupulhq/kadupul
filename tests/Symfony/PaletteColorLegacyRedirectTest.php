<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Application\Port\PaletteColorStore;
use Kadupul\Graphing\Application\Query\ListPaletteColors;
use Kadupul\Graphing\Infrastructure\Symfony\Controller\LegacyPaletteColorsController;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PaletteColorLegacyRedirectTest extends TestCase
{
    public function testLegacyEditorsPreserveValidatedContextAndRejectMalformedActions(): void
    {
        $actor = new Actor(9, 'palette-operator');
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn($actor);
        $access = $this->createMock(PaletteColorAccess::class);
        $access->method('authorize')->willReturn($actor);
        $store = $this->createMock(PaletteColorStore::class);
        $store->method('defaultRows')->willReturn(25);
        $store->method('defaultHasGraphs')->willReturn(false);
        $store->expects(self::never())->method('save');
        $store->expects(self::never())->method('delete');
        $store->expects(self::never())->method('list');
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $calls = [];
        $urls->method('generate')->willReturnCallback(static function (string $name, array $parameters) use (&$calls): string {
            $calls[] = [$name, $parameters];
            return '/' . $name . '?' . http_build_query($parameters);
        });
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn(string $text): string => $text);
        $controller = new LegacyPaletteColorsController();
        $list = new ListPaletteColors($access, $store);
        foreach (['0' => 'palette_color_create', '12' => 'palette_color_edit'] as $id => $route) {
            $request = Request::create('/graphing/colors/legacy', 'GET', ['action' => 'edit', 'id' => (string) $id, 'filter' => '東京 & "', 'named' => 'false']);
            $response = $controller($request, $console, $access, $store, $list, $urls, $translator);
            self::assertSame(302, $response->getStatusCode());
            [$name, $parameters] = end($calls);
            self::assertSame($route, $name);
            self::assertSame('東京 & "', $parameters['filter']);
            self::assertSame('false', $parameters['named']);
            if ($id !== 0) {
                self::assertSame((int) $id, $parameters['id']);
            } else {
                self::assertArrayNotHasKey('id', $parameters);
            }
        }
        foreach ([['action' => ['edit']], ['action' => 'edit', 'id' => '../12'], ['action' => 'edit', 'id' => ['12']], ['action' => 'edit', 'id' => '12', 'filter' => ['bad']], ['action' => 'unknown']] as $query) {
            self::assertSame(400, $controller(Request::create('/graphing/colors/legacy', 'GET', $query), $console, $access, $store, $list, $urls, $translator)->getStatusCode());
        }
        foreach (['actions', 'remove', 'save'] as $action) {
            $response = $controller(Request::create('/graphing/colors/legacy', 'GET', ['action' => $action]), $console, $access, $store, $list, $urls, $translator);
            self::assertSame(405, $response->getStatusCode());
            self::assertSame('GET, HEAD', $response->headers->get('Allow'));
        }
    }
}
