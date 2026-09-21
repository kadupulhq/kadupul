<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceCatalog;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Application\Query\ListDevices;
use Kadupul\Inventory\Application\ReadModel\DevicePage;
use Kadupul\Inventory\Domain\DeviceListCriteria;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InventoryTest extends TestCase
{
    #[DataProvider('deniedIdentities')]
    public function testDenialPreventsAnyDeviceQuery(?Actor $actor, bool $permitted): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn($actor);
        $access->method('canManageDevices')->willReturn($permitted);
        $catalog = $this->createMock(DeviceCatalog::class);
        $catalog->expects(self::never())->method('visibleTo');
        $this->expectException(InventoryAccessDenied::class);
        (new ListDevices($access, $catalog))(new DeviceListCriteria());
    }

    public static function deniedIdentities(): iterable
    {
        yield [null, false];
        yield [new Actor(42, 'viewer'), false];
    }

    public function testActorCannotChooseAnotherUsersVisibility(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $actor = new Actor(42, 'operator');
        $access->method('consoleActor')->willReturn($actor);
        $access->expects(self::once())->method('canManageDevices')->with($actor)->willReturn(true);
        $catalog = $this->createMock(DeviceCatalog::class);
        $criteria = new DeviceListCriteria(' router ', 'enabled', 2, 50, 'down');
        $page = new DevicePage([], false);
        $catalog->expects(self::once())->method('visibleTo')->with(42, $criteria)->willReturn($page);
        self::assertSame($page, (new ListDevices($access, $catalog))($criteria));
        self::assertSame('router', $criteria->search);
        self::assertSame(50, $criteria->offset());
    }

    #[DataProvider('invalidCriteria')]
    public function testQueryBoundsAreIndependentOfHttp(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeviceListCriteria(...$arguments);
    }

    public static function invalidCriteria(): iterable
    {
        yield [ ['page' => 0] ];
        yield [ ['page' => 100001] ];
        yield [ ['pageSize' => 1000] ];
        yield [ ['state' => 'any SQL'] ];
        yield [ ['status' => 'other'] ];
        yield [ ['status' => '3 OR 1=1'] ];
        yield [ ['search' => str_repeat('x', 201)] ];
    }
}
