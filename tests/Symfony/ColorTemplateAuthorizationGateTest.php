<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ColorTemplateAuthorizationGateTest extends TestCase
{
    public function testAnonymousRequestsAreRejectedBeforeFeatureAuthorizationOrInputReads(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $console = $this->createMock(ConsoleAccess::class);
            $console->expects(self::exactly(10))->method('consoleActor')->willReturn(null);
            $container->set(ConsoleAccess::class, $console);

            $featureAccess = $this->createMock(ColorTemplateAccess::class);
            $featureAccess->expects(self::never())->method('authorize');
            $container->set(ColorTemplateAccess::class, $featureAccess);

            $requests = [
                Request::create('/graphing/color-templates?filter[]=invalid'),
                Request::create('/graphing/color-templates/new'),
                Request::create('/graphing/color-templates/1/edit'),
                Request::create('/graphing/color-templates/actions?action=invalid&ids[]=bad'),
                Request::create('/graphing/color-templates/1/items/new'),
                Request::create('/graphing/color-templates/1/items/2/edit'),
                Request::create('/graphing/color-templates/1/items/2/delete'),
                Request::create('/graphing/color-templates/1/items/order', 'POST', ['unexpected' => 'bad']),
                Request::create('/graphing/color-templates/legacy?action=bad'),
                Request::create('/graphing/color-template-items/legacy?action=bad'),
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
