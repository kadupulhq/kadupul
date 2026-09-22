<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\RemoveDevices;
use Kadupul\Inventory\Application\Port\DeviceRemovals;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Application\Query\PrepareDeviceRemoval;
use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Domain\DeviceRemoval;
use Kadupul\Inventory\Domain\DeviceRemovalPolicy;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use PHPUnit\Framework\TestCase;

final class DeviceRemovalTest extends TestCase
{
    public function testChangedAssociationsInvalidateRemovalConfirmation(): void
    {
        $device = new DeviceState(7, 'Router', 'router.invalid', true, 0, 1, 0);
        $before = new DeviceRemoval($device, [11], [12]);
        $before->assertRevision($before->revision());
        foreach ([new DeviceRemoval($device, [11, 13], [12]), new DeviceRemoval($device, [11], [12, 13]), new DeviceRemoval($device, [], [12])] as $changed) {
            try {
                $changed->assertRevision($before->revision());
                self::fail('Changed associations accepted');
            } catch (DeviceEditConflict) {
                self::assertNotSame($before->revision(), $changed->revision());
            }
        }
    }
    public function testAuthorizedCommandsPreservePolicyAndAllExpectedRevisions(): void
    {
        foreach (DeviceRemovalPolicy::cases() as $policy) {
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $access->method('canManageDevices')->willReturn(true);
            $states = [new DeviceRemoval(new DeviceState(7, 'Router', 'router.invalid', true, 0, 1, 0), [11], [12])];
            $selection = new DeviceSelection([7 => $states[0]->revision()]);
            $port = $this->createMock(DeviceRemovals::class);
            $port->expects(self::once())->method('findVisible')->with(42, [7])->willReturn($states);
            $port->expects(self::once())->method('remove')->with(42, $selection, $policy);
            self::assertSame($states, (new PrepareDeviceRemoval($access, $port))(['7']));
            (new RemoveDevices($access, $port))($selection, $policy);
        }
    }
    public function testAuthorizationPrecedesAllReadsAndWrites(): void
    {
        foreach ([null, new Actor(42, 'operator')] as $actor) {
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn($actor);
            $access->method('canManageDevices')->willReturn(false);
            $port = $this->createMock(DeviceRemovals::class);
            $port->expects(self::never())->method('findVisible');
            $port->expects(self::never())->method('remove');
            foreach ([fn() => (new PrepareDeviceRemoval($access, $port))([7]), fn() => (new RemoveDevices($access, $port))(new DeviceSelection([7 => str_repeat('a', 64)]), DeviceRemovalPolicy::Purge)] as $action) {
                try {
                    $action();
                    self::fail('Unauthorized removal accepted');
                } catch (InventoryAccessDenied $error) {
                    self::assertSame($actor === null, $error->unauthenticated);
                }
            }
        }
    }
}
