<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\ChangeDeviceOptions;
use Kadupul\Inventory\Application\Port\DeviceOptions;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceSelection;
use PHPUnit\Framework\TestCase;

final class DeviceOptionsTest extends TestCase
{
    public function testAuthorizedResetPreservesSelectionAndActor(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access->method('canManageDevices')->willReturn(true);
        $selection = new DeviceSelection([7 => str_repeat('a', 64)]);
        $port = $this->createMock(DeviceOptions::class);
        $port->expects(self::once())->method('changeOptions')->with(42, $selection, self::callback(fn($change) => $change->fields === ['location' => 'Rack']));
        (new ChangeDeviceOptions($access, $port))($selection, new \Kadupul\Inventory\Domain\DeviceOptionsChange(['location' => 'Rack']));
    }

    public function testAuthorizationPrecedesReset(): void
    {
        foreach ([null, new Actor(42, 'operator')] as $actor) {
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn($actor);
            $access->method('canManageDevices')->willReturn(false);
            $port = $this->createMock(DeviceOptions::class);
            $port->expects(self::never())->method('changeOptions');
            try {
                (new ChangeDeviceOptions($access, $port))(new DeviceSelection([7 => str_repeat('a', 64)]), new \Kadupul\Inventory\Domain\DeviceOptionsChange(['location' => 'Rack']));
                self::fail('Unauthorized reset accepted');
            } catch (InventoryAccessDenied $error) {
                self::assertSame($actor === null, $error->unauthenticated);
            }
        }
    }
    public function testOnlyExplicitValidOptionsAreAccepted(): void
    {
        $change = new \Kadupul\Inventory\Domain\DeviceOptionsChange(['location' => '', 'snmp_timeout' => '0500']);
        self::assertSame(['snmp_timeout' => '500', 'location' => ''], $change->fields);
        foreach ([[], ['poller_id' => 2], ['snmp_community' => 'secret'], ['location' => []], ['location' => str_repeat('x', 41)], ['snmp_timeout' => '0'], ['ping_method' => '4'], ['max_oids' => '61']] as $fields) {
            try {
                new \Kadupul\Inventory\Domain\DeviceOptionsChange($fields);
                self::fail('Invalid options accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testChangedOptionsInvalidateBulkConfirmation(): void
    {
        $before = new \Kadupul\Inventory\Domain\DeviceState(1, 'Device', 'device.invalid', true, 0, 1, 0, ['location' => 'A']);
        $after = new \Kadupul\Inventory\Domain\DeviceState(1, 'Device', 'device.invalid', true, 0, 1, 0, ['location' => 'B']);
        $this->expectException(\Kadupul\Inventory\Domain\DeviceEditConflict::class);
        $after->assertRevision($before->revision());
    }
}
