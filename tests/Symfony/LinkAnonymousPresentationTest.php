<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LinkAnonymousPresentationTest extends TestCase
{
    public function testUnsupportedLegacyActionIsBadInputForAnAllowedMethod(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn(new \Kadupul\IdentityAccess\Contract\Actor(1, 'admin'));
            $container->set(ConsoleAccess::class, $console);
            $access = $this->createMock(LinkAccess::class);
            $access->method('authorize')->willReturn(new \Kadupul\IdentityAccess\Contract\Actor(1, 'admin'));
            $container->set(LinkAccess::class, $access);
            foreach (['GET', 'HEAD'] as $method) {
                $request = Request::create('/links/legacy?action=unsupported', $method);
                $response = $kernel->handle($request);
                self::assertSame(400, $response->getStatusCode());
                self::assertFalse($response->headers->has('Allow'));
                self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
                $kernel->terminate($request, $response);
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testAnonymousRequestsAreRejectedBeforePresetAuthorizationOrInputReads(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');

            $console = $this->createMock(ConsoleAccess::class);
            $console->expects(self::exactly(5))->method('consoleActor')->willReturn(null);
            $container->set(ConsoleAccess::class, $console);

            $access = $this->createMock(LinkAccess::class);
            $access->expects(self::never())->method('authorize');
            $container->set(LinkAccess::class, $access);

            $requests = [
                Request::create('/links?filter[]=malformed'),
                Request::create('/links/new'),
                Request::create('/links/1/edit'),
                Request::create('/links/action/delete?ids[]=malformed'),
                Request::create('/links/legacy?action=malformed'),
            ];

            foreach ($requests as $request) {
                $response = $kernel->handle($request);
                self::assertSame(401, $response->getStatusCode(), (string) $request->getRequestUri());
                $kernel->terminate($request, $response);
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
