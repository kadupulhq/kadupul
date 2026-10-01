<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\DataInput\Application\Port\DataInputAccess;
use Kadupul\DataInput\Application\Port\DataInputGateway;
use Kadupul\DataInput\Application\DataInputDenied;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DataInputAnonymousPresentationTest extends TestCase
{
    public function testAnonymousRequestsAreRejectedBeforeFeatureAuthorizationOrInputReads(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');

            $console = $this->createMock(ConsoleAccess::class);
            $console->expects(self::exactly(7))->method('consoleActor')->willReturn(null);
            $container->set(ConsoleAccess::class, $console);

            $access = $this->createMock(DataInputAccess::class);
            $access->expects(self::never())->method('authorize');
            $container->set(DataInputAccess::class, $access);

            $requests = [
                Request::create('/data-inputs?filter[]=invalid'),
                Request::create('/data-inputs/actions/delete?ids[]=invalid'),
                Request::create('/data-inputs/new'),
                Request::create('/data-inputs/1/edit'),
                Request::create('/data-inputs/1/fields/0?direction[]=invalid'),
                Request::create('/data-inputs/1/delete?field[]=invalid'),
                Request::create('/data-inputs/legacy?action[]=invalid'),
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
    public function testConsoleOnlyRequestsRequireFeatureAuthorizationBeforeParsing(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn(new Actor(9, 'console-only'));
            $container->set(ConsoleAccess::class, $console);
            $access = $this->createMock(DataInputAccess::class);
            $access->expects(self::exactly(7))->method('authorize')->willThrowException(new DataInputDenied());
            $container->set(DataInputAccess::class, $access);
            $gateway = $this->createMock(DataInputGateway::class);
            $gateway->expects(self::never())->method('execute');
            $container->set(DataInputGateway::class, $gateway);
            foreach ([
                '/data-inputs?filter[]=invalid',
                '/data-inputs/actions/delete?ids[]=invalid',
                '/data-inputs/new',
                '/data-inputs/1/edit',
                '/data-inputs/1/fields/0?direction[]=invalid',
                '/data-inputs/1/delete?field[]=invalid',
                '/data-inputs/legacy?action[]=invalid',
            ] as $url) {
                $request = Request::create($url);
                $response = $kernel->handle($request);
                self::assertSame(403, $response->getStatusCode(), $url);
                $kernel->terminate($request, $response);
            }
        } finally {
            $kernel->shutdown();
        }
    }

}
