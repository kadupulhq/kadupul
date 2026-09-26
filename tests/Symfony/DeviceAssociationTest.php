<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\ChangeDeviceAssociation;
use Kadupul\Inventory\Application\Port\DeviceAssociationStore;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceAssociations;
use Kadupul\Inventory\Domain\DeviceAssociationChange;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use PHPUnit\Framework\TestCase;

final class DeviceAssociationTest extends TestCase
{
    public function testAuthorizedChangePreservesActorAndRevision(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access->method('canManageDevices')->willReturn(true);
        $device = new DeviceAssociations(7, 'Router', 0, 1, 0, []);
        $change = new DeviceAssociationChange('graph', 'add', 3);
        $port = $this->createMock(DeviceAssociationStore::class);
        $port->method('findVisible')->willReturn($device);
        $port->expects(self::once())->method('change')->with(42, 7, $change, $device->revision());
        (new ChangeDeviceAssociation($access, $port))(7, $change, $device->revision());
    }
    public function testUnauthorizedChangesCannotReadOrWriteAssociations(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(null);
        $port = $this->createMock(DeviceAssociationStore::class);
        $port->expects(self::never())->method('findVisible');
        $port->expects(self::never())->method('change');
        $this->expectException(InventoryAccessDenied::class);
        (new ChangeDeviceAssociation($access, $port))(7, new DeviceAssociationChange('graph', 'add', 3), '');
    }
    public function testConcurrentAssociationChangesInvalidateRevision(): void
    {
        $before = new DeviceAssociations(7, 'Router', 0, 1, 0, []);
        $after = new DeviceAssociations(7, 'Router', 0, 1, 0, [3 => 'Graph']);
        $this->expectException(DeviceEditConflict::class);
        $after->assertChange(new DeviceAssociationChange('graph', 'remove', 3), $before->revision());
    }
    public function testInvalidTransitionsAreRejected(): void
    {
        foreach ([['add', [3 => 'Graph']], ['remove', []]] as [$operation, $items]) {
            $device = new DeviceAssociations(7, 'Router', 0, 1, 0, $items);
            try {
                $device->assertChange(new DeviceAssociationChange('graph', $operation, 3), $device->revision());
                self::fail('Invalid transition accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
    public function testQueryMethodChangesInvalidateConfirmation(): void
    {
        $before = new DeviceAssociations(7, 'Router', 0, 1, 0, [3 => 'Query'], [3 => 2], 'query');
        $after = new DeviceAssociations(7, 'Router', 0, 1, 0, [3 => 'Query'], [3 => 3], 'query');
        $this->expectException(DeviceEditConflict::class);
        $after->assertChange(new DeviceAssociationChange('query', 'change', 3, 0), $before->revision());
    }
    public function testUptimeReindexRequiresSnmp(): void
    {
        $device = new DeviceAssociations(7, 'Router', 0, 1, 0, [], [], 'query', 0);
        $this->expectException(\InvalidArgumentException::class);
        $device->assertChange(new DeviceAssociationChange('query', 'add', 3, 1), $device->revision());
    }

}
