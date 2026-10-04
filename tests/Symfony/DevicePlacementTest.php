<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DevicePlacement;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DevicePlacements;
use Kadupul\Inventory\Application\Command\PlaceDevices;
use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use PHPUnit\Framework\TestCase;

final class DevicePlacementTest extends TestCase
{
    public function testDestinationAndDisplaySettingsAreBounded(): void
    {
        foreach ([['tree','1:0',0,0], ['tree','1:7',0,0], ['report','7',28,3]] as $args) {
            self::assertGreaterThan(0, (new DevicePlacement(...$args))->targetId);
        }
        foreach ([['other','1',0,0], ['tree','1',0,0], ['tree','1:0',1,0], ['report','1',29,1], ['report','1',7,4], ['tree','1:4294967296',0,0], ['report','01',7,1]] as $args) {
            try {
                new DevicePlacement(...$args);
                self::fail('Invalid placement accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
    public function testAuthorizationPrecedesPlacement(): void
    {
        foreach ([null, new Actor(42, 'operator')] as $actor) {
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn($actor);
            $access->method('canManageDevices')->willReturn(false);
            $port = $this->createMock(DevicePlacements::class);
            $port->expects(self::never())->method('place');
            try {
                (new PlaceDevices($access, $port))(new DeviceSelection([7 => str_repeat('a', 64)]), new DevicePlacement('tree', '1:0'));
                self::fail('Unauthorized placement accepted');
            } catch (InventoryAccessDenied $error) {
                self::assertSame($actor === null, $error->unauthenticated);
            }
        }
    }
}
