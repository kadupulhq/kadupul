<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\GraphDefinition\Application\Port\VdefCatalog;
use Kadupul\GraphDefinition\Application\Port\VdefEditor;
use Kadupul\GraphDefinition\Application\Port\VdefRealmAccess;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class VdefFeatureAuthorizationTest extends TestCase
{
    public function testConsoleActorWithoutDefinitionRealmIsRefusedBeforeParsingOrPersistence(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn(new Actor(42, 'console-only'));
            $container->set(ConsoleAccess::class, $console);
            $realm = $this->createMock(VdefRealmAccess::class);
            $realm->method('canManageDefinitions')->willReturn(false);
            $container->set(VdefRealmAccess::class, $realm);
            $catalog = $this->createMock(VdefCatalog::class);
            foreach (['list', 'count', 'find'] as $method) {
                $catalog->expects(self::never())->method($method);
            }
            $container->set(VdefCatalog::class, $catalog);
            $editor = $this->createMock(VdefEditor::class);
            foreach (['save', 'saveItem', 'deleteItem', 'reorder', 'act'] as $method) {
                $editor->expects(self::never())->method($method);
            }
            $container->set(VdefEditor::class, $editor);
            foreach ([
                Request::create('/graph-definitions/vdefs?page[]=bad'),
                Request::create('/graph-definitions/vdefs/new', 'POST', ['vdef_edit' => 'bad']),
                Request::create('/graph-definitions/vdefs/1/edit'),
                Request::create('/graph-definitions/vdefs/1/items/1?type[]=bad'),
                Request::create('/graph-definitions/vdefs/1/items/1/delete'),
                Request::create('/graph-definitions/vdefs/1/items/reorder', 'POST', ['order' => 'bad']),
                Request::create('/graph-definitions/vdefs/actions/delete'),
                Request::create('/graph-definitions/vdefs/actions/duplicate?ids[]=bad'),
                Request::create('/graph-definitions/vdefs/legacy?action[]=bad'),
            ] as $request) {
                self::assertSame(403, $kernel->handle($request)->getStatusCode(), $request->getRequestUri());
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
