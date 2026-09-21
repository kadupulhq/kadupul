<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceDetailsReader;
use Kadupul\Inventory\Application\Query\FindDeviceDetails;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Application\ReadModel\DeviceDetails;
use Kadupul\Inventory\Application\ReadModel\DeviceSummary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceDetailsTest extends TestCase
{
    #[DataProvider('deniedActors')]
    public function testUnauthorizedActorsCannotReadDetails(?Actor $actor): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn($actor);
        $access->method('canManageDevices')->willReturn(false);
        $reader = $this->createMock(DeviceDetailsReader::class);
        $reader->expects(self::never())->method('findVisible');
        $this->expectException(InventoryAccessDenied::class);
        (new FindDeviceDetails($access, $reader))(12);
    }

    public static function deniedActors(): iterable
    {
        yield [null];
        yield [new Actor(42, 'operator')];
    }

    #[DataProvider('details')]
    public function testReadIsScopedToCurrentActor(?DeviceDetails $details): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $actor = new Actor(42, 'operator');
        $access->method('consoleActor')->willReturn($actor);
        $access->expects(self::once())->method('canManageDevices')->with($actor)->willReturn(true);
        $reader = $this->createMock(DeviceDetailsReader::class);
        $reader->expects(self::once())->method('findVisible')->with(42, 12)->willReturn($details);
        self::assertSame($details, (new FindDeviceDetails($access, $reader))(12));
    }

    public static function details(): iterable
    {
        yield [null];
        yield [new DeviceDetails(new DeviceSummary(12, 'Router', 'router.invalid', false, 'Up', 'West', 'asset-1'), 'Notes', 1, 'HQ')];
    }
}
