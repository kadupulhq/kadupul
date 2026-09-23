<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\SetDevicesEnabled;
use Kadupul\Inventory\Application\Port\DeviceStates;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Application\Query\PrepareDeviceStateChange;
use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use PHPUnit\Framework\TestCase;

final class DeviceStateTest extends TestCase
{
    public function testSelectionRejectsAmbiguousOrUnboundedIdentifiers(): void
    {
        foreach ([[], range(1, 101), [1, '1'], [0], [-1], ['01'], [true], [1.5], ['1e1'], [16777216], [[]]] as $ids) {
            try {
                DeviceSelection::validateIds($ids);
                self::fail('Invalid selection accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame([1, 3, 16777215], DeviceSelection::validateIds(['16777215', '3', 1]));
        self::assertCount(100, DeviceSelection::validateIds(range(1, 100)));
        $selection = new DeviceSelection([3 => str_repeat('b', 64), 1 => str_repeat('a', 64)]);
        self::assertSame([1, 3], array_keys($selection->revisions));
        foreach (['', str_repeat('A', 64), str_repeat('a', 63), null, []] as $revision) {
            try {
                new DeviceSelection([1 => $revision]);
                self::fail('Invalid revision accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEveryDisplayedIdentityAndAssignmentChangeInvalidatesConfirmation(): void
    {
        $values = [7, 'Router', 'router.invalid', true, 2, 1, 4];
        $before = new DeviceState(...$values);
        $before->assertRevision($before->revision());
        foreach ([8, 'Renamed', 'new.invalid', false, 3, 2, 5] as $index => $replacement) {
            $changed = new DeviceState(...array_replace($values, [$index => $replacement]));
            try {
                $changed->assertRevision($before->revision());
                self::fail('Stale confirmation accepted');
            } catch (DeviceEditConflict) {
                self::assertNotSame($before->revision(), $changed->revision());
            }
        }
    }

    public function testAuthorizedQueriesNormalizeSelectionAndCommandsPreserveExpectedRevisions(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access->method('canManageDevices')->willReturn(true);
        $states = [new DeviceState(7, 'Router', 'router.invalid', true, 0, 1, 0)];
        $selection = new DeviceSelection([7 => $states[0]->revision()]);
        $port = $this->createMock(DeviceStates::class);
        $port->expects(self::once())->method('findVisible')->with(42, [7])->willReturn($states);
        $port->expects(self::once())->method('setEnabled')->with(42, $selection, false);
        self::assertSame($states, (new PrepareDeviceStateChange($access, $port))(['7']));
        (new SetDevicesEnabled($access, $port))($selection, false);
    }

    public function testAuthorizationPrecedesReadsAndWrites(): void
    {
        foreach ([null, new Actor(42, 'operator')] as $actor) {
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn($actor);
            $access->method('canManageDevices')->willReturn(false);
            $port = $this->createMock(DeviceStates::class);
            $port->expects(self::never())->method('findVisible');
            $port->expects(self::never())->method('setEnabled');
            foreach ([fn() => (new PrepareDeviceStateChange($access, $port))([]), fn() => (new SetDevicesEnabled($access, $port))(new DeviceSelection([7 => str_repeat('a', 64)]), false)] as $action) {
                try {
                    $action();
                    self::fail('Unauthorized action accepted');
                } catch (InventoryAccessDenied $error) {
                    self::assertSame($actor === null, $error->unauthenticated);
                }
            }
        }
    }
}
