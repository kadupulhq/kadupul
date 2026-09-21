<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\SiteEditor;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\Site;
use Kadupul\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SiteEditAuthorizationTest extends TestCase
{
    #[DataProvider('revocations')]
    public function testAuthorizationLossDuringPostPreservesStatus(bool $anonymous, bool $atPersistence): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $actor = new Actor(42, 'operator');
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturnOnConsecutiveCalls($actor, $actor, $anonymous && !$atPersistence ? null : $actor);
            $access->method('canManageDevices')->willReturnOnConsecutiveCalls(true, true, $atPersistence);
            $container->set(ConsoleAccess::class, $access);
            $site = new Site(12, 'Original', '');
            $revision = $site->revision();
            $sites = $this->createMock(SiteEditor::class);
            $sites->expects(self::exactly($atPersistence ? 3 : 2))->method('find')->with(12)->willReturn($site);
            if ($atPersistence) {
                $sites->expects(self::once())->method('save')->willThrowException(new InventoryAccessDenied($anonymous));
            } else {
                $sites->expects(self::never())->method('save');
            }
            $container->set(SiteEditor::class, $sites);
            $path = '/inventory/sites/12/edit';
            $page = $kernel->handle(Request::create($path));
            self::assertSame(200, $page->getStatusCode());
            $document = new \DOMDocument();
            @$document->loadHTML($page->getContent());
            $token = (new \DOMXPath($document))->evaluate('string(//input[@name="site_edit[_token]"]/@value)');
            self::assertNotSame('', $token);
            $request = Request::create($path, 'POST', ['site_edit' => ['name' => 'Changed', 'notes' => '', 'revision' => $revision, '_token' => $token]]);
            $request->headers->set('Origin', 'http://localhost');
            $response = $kernel->handle($request);
            self::assertSame($anonymous ? 401 : 403, $response->getStatusCode());
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        } finally {
            $kernel->shutdown();
        }
    }

    public static function revocations(): iterable
    {
        yield 'session lost before command' => [true, false];
        yield 'realm lost before command' => [false, false];
        yield 'session lost before persistence' => [true, true];
        yield 'realm lost before persistence' => [false, true];
    }
}
