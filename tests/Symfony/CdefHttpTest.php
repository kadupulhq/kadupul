<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\GraphDefinition\Application\Port\CdefCatalog;
use Kadupul\GraphDefinition\Application\Port\CdefEditor;
use Kadupul\GraphDefinition\Application\Port\CdefRealmAccess;
use Kadupul\GraphDefinition\Domain\CdefSummary;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;

final class CdefHttpTest extends TestCase
{
    public function testListRouteRendersEscapedPrivateNoStoreContentAfterRealmAuthorization(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $this->authorize($container, true);
            $catalog = $this->createMock(CdefCatalog::class);
            $catalog->method('list')->willReturn([new CdefSummary(7, '<script>router</script>', 1, 0, 0)]);
            $catalog->method('count')->willReturn(1);
            $container->set(CdefCatalog::class, $catalog);

            $response = $kernel->handle(Request::create('/graph-definitions/cdefs', 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertStringContainsString('&lt;script&gt;router&lt;/script&gt;', $response->getContent());
            self::assertStringNotContainsString('<script>router</script>', $response->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testListRouteUsesGraphDefinitionFrenchCatalogue(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $this->authorize($container, true);
            $catalog = $this->createMock(CdefCatalog::class);
            $catalog->method('list')->willReturn([]);
            $catalog->method('count')->willReturn(0);
            $container->set(CdefCatalog::class, $catalog);

            $request = Request::create('/graph-definitions/cdefs', 'GET', [], ['Cacti' => 'fixture']);
            $request->setLocale('fr');
            $response = $kernel->handle($request);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('<h1>CDEF</h1>', $response->getContent());
            self::assertStringContainsString('Rechercher', $response->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testCreateRouteRejectsMissingCsrfAndDoesNotHandOffMutation(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $this->authorize($container, true);
            $editor = $this->createMock(CdefEditor::class);
            $editor->expects(self::never())->method('save');
            $container->set(CdefEditor::class, $editor);

            $request = Request::create('/graph-definitions/cdefs/new', 'POST', [
                'cdef_edit' => ['id' => '0', 'name' => 'Should not persist', '_token' => 'invalid'],
            ], ['Cacti' => 'fixture']);
            $request->headers->set('Origin', 'http://localhost');
            $response = $kernel->handle($request);
            self::assertSame(422, $response->getStatusCode());
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testCreateRouteAcceptsSameOriginTokenAndPersistsThroughEditorPort(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $this->authorize($container, true);
            $catalog = $this->createMock(CdefCatalog::class);
            $container->set(CdefCatalog::class, $catalog);
            $editor = $this->createMock(CdefEditor::class);
            $editor->expects(self::once())->method('save')->with(42, 0, 'New CDEF')->willReturn(19);
            $container->set(CdefEditor::class, $editor);

            $page = $kernel->handle(Request::create('/graph-definitions/cdefs/new', 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $page->getStatusCode());
            $document = new \DOMDocument();
            @$document->loadHTML($page->getContent());
            $token = (new \DOMXPath($document))->evaluate('string(//input[@name="cdef_edit[_token]"]/@value)');
            self::assertNotSame('', $token);

            $request = Request::create('/graph-definitions/cdefs/new', 'POST', [
                'cdef_edit' => ['id' => '0', 'name' => 'New CDEF', '_token' => $token],
            ], ['Cacti' => 'fixture']);
            $request->headers->set('Origin', 'http://localhost');
            $response = $kernel->handle($request);
            self::assertSame(303, $response->getStatusCode());
            self::assertSame('/graph-definitions/cdefs/19/edit', $response->headers->get('Location'));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testUnauthenticatedListRouteReturns401WithoutCatalogHandoff(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $this->authorize($container, false);
            $catalog = $this->createMock(CdefCatalog::class);
            $catalog->expects(self::never())->method('list');
            $catalog->expects(self::never())->method('count');
            $container->set(CdefCatalog::class, $catalog);

            $response = $kernel->handle(Request::create('/graph-definitions/cdefs?page[]=invalid', 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(401, $response->getStatusCode());
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        } finally {
            $kernel->shutdown();
        }
    }

    #[DataProvider('featureProtectedRoutes')]
    public function testConsoleAccountWithoutDefinitionRealmIsDeniedBeforeParsingOrHandoff(string $path, string $method): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'console-only'));
            $container->set(ConsoleAccess::class, $access);
            $realm = $this->createMock(CdefRealmAccess::class);
            $realm->expects(self::once())->method('canManageDefinitions')->with(42)->willReturn(false);
            $container->set(CdefRealmAccess::class, $realm);
            foreach ([CdefCatalog::class, CdefEditor::class] as $port) {
                $handoff = $this->createMock($port);
                foreach (get_class_methods($port) as $operation) {
                    $handoff->expects(self::never())->method($operation);
                }
                $container->set($port, $handoff);
            }

            $response = $kernel->handle(Request::create($path, $method));
            self::assertSame(403, $response->getStatusCode());
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        } finally {
            $kernel->shutdown();
        }
    }

    public static function featureProtectedRoutes(): array
    {
        return [
            'malformed list' => ['/graph-definitions/cdefs?page[]=invalid', 'GET'],
            'missing create form' => ['/graph-definitions/cdefs/new', 'POST'],
            'malformed edit query' => ['/graph-definitions/cdefs/1/edit?page[]=invalid', 'GET'],
            'malformed item type' => ['/graph-definitions/cdefs/1/items/1?type[]=invalid', 'GET'],
            'missing item confirmation' => ['/graph-definitions/cdefs/1/items/1/delete', 'POST'],
            'missing reorder selection' => ['/graph-definitions/cdefs/1/items/reorder', 'POST'],
            'missing action selection' => ['/graph-definitions/cdefs/actions/delete', 'GET'],
            'malformed legacy query' => ['/graph-definitions/cdefs/legacy?action=edit&id[]=invalid', 'GET'],
        ];
    }

    private function authorize(object $container, bool $authenticated): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn($authenticated ? new Actor(42, 'operator') : null);
        $container->set(ConsoleAccess::class, $access);

        $realm = $this->createMock(CdefRealmAccess::class);
        $realm->method('canManageDefinitions')->willReturn(true);
        $container->set(CdefRealmAccess::class, $realm);
    }
}
