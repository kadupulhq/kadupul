<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\SiteCatalog;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Application\Query\ListSites;
use Kadupul\Inventory\Application\ReadModel\SitePage;
use Kadupul\Inventory\Domain\SiteListCriteria;
use Kadupul\Inventory\Infrastructure\Symfony\SiteListParameters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SiteCatalogTest extends TestCase
{
    #[DataProvider('deniedActors')]
    public function testDenialPreventsCatalogAccess(?Actor $actor): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn($actor);
        $access->method('canManageDevices')->willReturn(false);
        $catalog = $this->createMock(SiteCatalog::class);
        $catalog->expects(self::never())->method('listFor');
        $this->expectException(InventoryAccessDenied::class);
        (new ListSites($access, $catalog))(new SiteListCriteria());
    }

    public static function deniedActors(): iterable
    {
        yield [null];
        yield [new Actor(42, 'viewer')];
    }

    public function testDeviceCountsUseCurrentActorAndValidatedCriteria(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $actor = new Actor(42, 'operator');
        $access->method('consoleActor')->willReturn($actor);
        $access->expects(self::once())->method('canManageDevices')->with($actor)->willReturn(true);
        $catalog = $this->createMock(SiteCatalog::class);
        $criteria = new SiteListCriteria(' Tokyo ', 2, 50, 'desc');
        $page = new SitePage([], false);
        $catalog->expects(self::once())->method('listFor')->with(42, $criteria)->willReturn($page);
        self::assertSame($page, (new ListSites($access, $catalog))($criteria));
        self::assertSame('Tokyo', $criteria->search);
        self::assertSame(50, $criteria->offset());
        self::assertEquals($criteria, SiteListParameters::parse(['q' => ' Tokyo ', 'page' => '2', 'size' => '50', 'direction' => 'desc']));
        self::assertSame(['q' => 'Tokyo', 'page' => 2, 'size' => 50, 'direction' => 'desc'], SiteListParameters::encode($criteria));
    }

    #[DataProvider('invalidCriteria')]
    public function testDomainBoundsApplyOutsideHttp(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SiteListCriteria(...$arguments);
    }

    public static function invalidCriteria(): iterable
    {
        yield [ [str_repeat('x', 201)] ];
        yield [ ["\xff"] ];
        yield [ ["a\0b"] ];
        yield [ ['', 0] ];
        yield [ ['', 100001] ];
        yield [ ['', 1, 26] ];
        yield [ ['', 1, 25, 'invalid'] ];
    }

    #[DataProvider('invalidParameters')]
    public function testHttpRejectsMalformedParameters(array $query): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SiteListParameters::parse($query);
    }

    public static function invalidParameters(): iterable
    {
        foreach (['q', 'page', 'size', 'direction'] as $key) {
            yield [[$key => ['bad']]];
        }
        yield [['page' => '-1']];
        yield [['page' => '1000000']];
        yield [['size' => '1000']];
        yield [['size' => '2.5']];
    }
}
