<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\CollectorAdministration\Application\Port\CollectorCatalog;
use Kadupul\CollectorAdministration\Application\ReadModel\CollectorPage;
use Kadupul\CollectorAdministration\Application\ReadModel\CollectorSummary;
use Kadupul\CollectorAdministration\Domain\CollectorListCriteria;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\TestCase;

final class CollectorListPresentationTest extends TestCase
{
    public function testReadOnlyListRendersEscapedDataAndValidatedSearch(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $access->method('canManageDevices')->willReturn(true);
            $container->set(ConsoleAccess::class, $access);
            $catalog = $this->createMock(CollectorCatalog::class);
            $catalog->expects(self::exactly(2))->method('list')->with(new CollectorListCriteria('router'))->willReturn(new CollectorPage([
                new CollectorSummary(7, '<router>', '<router.example>', 'Running', 2, 4, 8.5, 2.25, 4.0, 3, 8, 2, 1, '2026-09-27 10:00:00', '2026-09-27 10:00:01', null),
            ], true, 2));
            $container->set(CollectorCatalog::class, $catalog);

            $response = $kernel->handle(Request::create('/collectors?collector_filter%5Bq%5D=router'));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('private, no-store', $response->headers->get('Cache-Control'));
            self::assertStringContainsString('&lt;router&gt;', $response->getContent());
            self::assertStringContainsString('&lt;router.example&gt;', $response->getContent());
            self::assertStringNotContainsString('<router>', $response->getContent());
            self::assertStringContainsString('collector_filter[q]', $response->getContent());
            self::assertStringContainsString('rel="next"', $response->getContent());
            self::assertStringNotContainsString('Delete selected', $response->getContent());

            $head = $kernel->handle(Request::create('/collectors?collector_filter%5Bq%5D=router', 'HEAD'));
            self::assertSame(200, $head->getStatusCode());
            self::assertSame('', $head->getContent());
        } finally {
            $kernel->shutdown();
        }
    }
}
