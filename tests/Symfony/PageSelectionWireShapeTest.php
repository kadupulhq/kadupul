<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\GraphDefinition\Application\Port\VdefCatalog;
use Kadupul\GraphDefinition\Application\Port\VdefEditor;
use Kadupul\GraphDefinition\Application\Port\VdefRealmAccess;
use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Application\Port\PaletteColorStore;
use Kadupul\Graphing\Domain\PaletteColor;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PageSelectionWireShapeTest extends TestCase
{
    public static function selections(): iterable
    {
        foreach (['vdef-delete', 'vdef-duplicate', 'palette-delete'] as $operation) {
            yield $operation . ' numeric-key object' => [$operation, '{"0":1}', false];
            yield $operation . ' rendered array' => [$operation, '[1]', true];
        }
    }

    #[DataProvider('selections')]
    public function testOnlyRenderedArrayShapeReachesMutation(string $operation, string $selection, bool $admitted): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $actor = new Actor(42, 'operator');
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn($actor);
            $container->set(ConsoleAccess::class, $console);
            $calls = [];
            if ($operation === 'palette-delete') {
                $access = $this->createMock(PaletteColorAccess::class);
                $access->method('authorize')->willReturn($actor);
                $container->set(PaletteColorAccess::class, $access);
                $color = new PaletteColor(1, 'Operator color', '123456', false);
                $store = $this->createMock(PaletteColorStore::class);
                $store->method('findMany')->willReturn([$color]);
                $store->method('delete')->willReturnCallback(static function (...$arguments) use (&$calls): void {
                    $calls[] = $arguments;
                });
                $container->set(PaletteColorStore::class, $store);
                $url = '/graphing/colors/actions/delete?ids[]=1';
                $form = 'palette_color_delete';
                $revision = $color->revision;
            } else {
                $realm = $this->createMock(VdefRealmAccess::class);
                $realm->method('canManageDefinitions')->willReturn(true);
                $container->set(VdefRealmAccess::class, $realm);
                $revision = str_repeat('a', 64);
                $catalog = $this->createMock(VdefCatalog::class);
                $catalog->method('selected')->willReturn([1 => ['id' => 1, 'name' => 'Operator VDEF', 'revision' => $revision]]);
                $container->set(VdefCatalog::class, $catalog);
                $editor = $this->createMock(VdefEditor::class);
                $editor->method('act')->willReturnCallback(static function (...$arguments) use (&$calls): void {
                    $calls[] = $arguments;
                });
                $container->set(VdefEditor::class, $editor);
                $url = '/graph-definitions/vdefs/actions/' . substr($operation, 5) . '?ids[]=1';
                $form = 'vdef_action';
            }
            $data = ['selection' => $selection, 'revisions' => json_encode([1 => $revision], JSON_THROW_ON_ERROR), '_token' => 'csrf-token'];
            if ($operation !== 'palette-delete') {
                $data['title_format'] = '<vdef_title> copy';
            }
            $request = Request::create($url, 'POST', [$form => $data], server: ['HTTP_ORIGIN' => 'http://localhost']);
            $response = $kernel->handle($request);
            if ($admitted) {
                self::assertSame(303, $response->getStatusCode(), $response->getContent());
                self::assertCount(1, $calls);
                self::assertSame(42, $calls[0][0]);
                self::assertSame([1], $calls[0][$operation === 'palette-delete' ? 1 : 2]);
                self::assertSame([1 => $revision], $calls[0][$operation === 'palette-delete' ? 2 : 4]);
            } else {
                self::assertContains($response->getStatusCode(), [400, 409, 422]);
                self::assertSame([], $calls);
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
