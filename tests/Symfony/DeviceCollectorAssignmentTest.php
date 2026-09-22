<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\AssignDeviceCollector;
use Kadupul\Inventory\Application\Port\DeviceCollectorAssignments;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Application\Query\PrepareDeviceCollectorAssignment;
use Kadupul\Inventory\Domain\DeviceCollectorAssignment;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use PHPUnit\Framework\TestCase;

final class DeviceCollectorAssignmentTest extends TestCase
{
    public function testStaleAndInvalidAssignmentsDoNotMutate(): void
    {
        $device = new DeviceCollectorAssignment(7, 'Router', 1, 1);
        foreach ([0, -1, 16777216] as $invalid) {
            try {
                $device->assign($invalid, $device->revision());
                self::fail('Invalid collector accepted');
            } catch (\InvalidArgumentException) {
                self::assertSame(1, $device->collectorId());
            }
        }
        $revision = $device->revision();
        $device->assign(3, $revision);
        self::assertSame(3, $device->collectorId());
        self::assertNotSame($revision, $device->revision());
        $this->expectException(DeviceEditConflict::class);
        $device->assign(2, $revision);
    }

    public function testTemplateChangesInvalidateCollectorConfirmation(): void
    {
        $before = new DeviceCollectorAssignment(7, 'Router', 1, 1);
        $moved = new DeviceCollectorAssignment(7, 'Router', 1, 2);
        $this->expectException(DeviceEditConflict::class);
        $moved->assign(2, $before->revision());
    }

    public function testAuthorizedCommandUsesVisibleAggregateAndExpectedRevision(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access->method('canManageDevices')->willReturn(true);
        $device = new DeviceCollectorAssignment(7, 'Router', 1, 1);
        $revision = $device->revision();
        $port = $this->createMock(DeviceCollectorAssignments::class);
        $port->expects(self::once())->method('findVisible')->with(42, 7)->willReturn($device);
        $port->expects(self::once())->method('save')->with(42, self::callback(fn($value) => $value->collectorId() === 2), $revision);
        (new AssignDeviceCollector($access, $port))(7, 2, $revision);
    }

    public function testDeniedCommandsAndQueriesDoNotReadOrWrite(): void
    {
        foreach ([null, new Actor(42, 'operator')] as $actor) {
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn($actor);
            $access->method('canManageDevices')->willReturn(false);
            $port = $this->createMock(DeviceCollectorAssignments::class);
            $port->expects(self::never())->method('findVisible');
            $port->expects(self::never())->method('collectors');
            $port->expects(self::never())->method('save');
            foreach ([fn() => (new AssignDeviceCollector($access, $port))(7, 2, ''), fn() => (new PrepareDeviceCollectorAssignment($access, $port))(7)] as $action) {
                try {
                    $action();
                    self::fail('Denied action accepted');
                } catch (InventoryAccessDenied $error) {
                    self::assertSame($actor === null, $error->unauthenticated);
                }
            }
        }
    }

    public function testMissingAndHiddenDevicesCannotBeWritten(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access->method('canManageDevices')->willReturn(true);
        $port = $this->createMock(DeviceCollectorAssignments::class);
        $port->method('findVisible')->willReturn(null);
        $port->expects(self::never())->method('collectors');
        $port->expects(self::never())->method('save');
        self::assertSame(['device' => null, 'collectors' => []], (new PrepareDeviceCollectorAssignment($access, $port))(7));
        $this->expectException(InventoryAccessDenied::class);
        (new AssignDeviceCollector($access, $port))(7, 2, '');
    }
}
