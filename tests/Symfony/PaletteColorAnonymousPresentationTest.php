<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PaletteColorAnonymousPresentationTest extends TestCase
{
    public function testAnonymousRequestsAreRejectedBeforePresetAuthorizationOrInputReads(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');

            $console = $this->createMock(ConsoleAccess::class);
            $console->expects(self::exactly(7))->method('consoleActor')->willReturn(null);
            $container->set(ConsoleAccess::class, $console);

            $access = $this->createMock(PaletteColorAccess::class);
            $access->expects(self::never())->method('authorize');
            $container->set(PaletteColorAccess::class, $access);

            $requests = [
                Request::create('/graphing/colors?filter[]=malformed'),
                Request::create('/graphing/colors/new'),
                Request::create('/graphing/colors/1/edit'),
                Request::create('/graphing/colors/actions/delete?ids[]=malformed'),
                Request::create('/graphing/colors/legacy?action=malformed'),
                Request::create('/graphing/colors/import?bad[]=x'),
                Request::create('/graphing/colors/export?filter[]=bad'),
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
