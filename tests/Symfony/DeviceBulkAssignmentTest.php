<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\AssignDevices;
use Kadupul\Inventory\Application\Port\DeviceBulkAssignments;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceSelection;
use PHPUnit\Framework\TestCase;

final class DeviceBulkAssignmentTest extends TestCase
{
    public function testAuthorizedResetPreservesSelectionAndActor(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access->method('canManageDevices')->willReturn(true);
        $selection = new DeviceSelection([7 => str_repeat('a', 64)]);
        $port = $this->createMock(DeviceBulkAssignments::class);
        $port->expects(self::once())->method('assign')->with(42, $selection, self::callback(fn($change) => $change->kind === 'site' && $change->targetId === 3));
        (new AssignDevices($access, $port))($selection, new \Kadupul\Inventory\Domain\DeviceBulkAssignment('site', 3));
    }

    public function testAuthorizationPrecedesReset(): void
    {
        foreach ([null, new Actor(42, 'operator')] as $actor) {
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn($actor);
            $access->method('canManageDevices')->willReturn(false);
            $port = $this->createMock(DeviceBulkAssignments::class);
            $port->expects(self::never())->method('assign');
            try {
                (new AssignDevices($access, $port))(new DeviceSelection([7 => str_repeat('a', 64)]), new \Kadupul\Inventory\Domain\DeviceBulkAssignment('site', 3));
                self::fail('Unauthorized reset accepted');
            } catch (InventoryAccessDenied $error) {
                self::assertSame($actor === null, $error->unauthenticated);
            }
        }
    }
    public function testOnlyValidAssignmentsAreAccepted(): void
    {
        foreach ([['site', 0], ['site', 4294967295], ['template', 0], ['collector', 1]] as [$kind, $id]) {
            $assignment = new \Kadupul\Inventory\Domain\DeviceBulkAssignment($kind, $id);
            self::assertSame($id, $assignment->targetId);
        }
        foreach ([['site', -1], ['site', 4294967296], ['template', 16777216], ['collector', 0], ['snmp', 1]] as [$kind, $id]) {
            try {
                new \Kadupul\Inventory\Domain\DeviceBulkAssignment($kind, $id);
                self::fail('Invalid assignment accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
