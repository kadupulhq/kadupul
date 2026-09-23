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
    public function testUnchangedCollectorDoesNotRequireAFullReplicationSnapshot(): void
    {
        $row = ['site_id' => 0, 'poller_id' => 2, 'host_template_id' => 0, 'disabled' => ''];
        $identity = $this->createMock(\PDOStatement::class);
        $identity->method('execute')->willReturn(true);
        $identity->method('fetch')->willReturn($row);
        $ownership = $this->createMock(\PDOStatement::class);
        $ownership->method('execute')->willReturn(true);
        $ownership->method('fetchColumn')->willReturn(0);
        $primary = $this->createMock(\PDO::class);
        $primary->expects(self::exactly(2))->method('prepare')->willReturnCallback(static fn($sql) => str_starts_with($sql, 'SELECT poller_id') ? $identity : $ownership);
        $primary->expects(self::never())->method('query');
        $remote = $this->createMock(\PDO::class);
        $remote->expects(self::once())->method('prepare')->with(self::stringStartsWith('SELECT poller_id'))->willReturn($identity);
        $remote->expects(self::never())->method('query');
        (new \Kadupul\Inventory\Infrastructure\Legacy\DeviceBulkAssignmentWriter())->verify($primary, [2 => $remote], new \Kadupul\Inventory\Domain\DeviceState(7, 'Router', 'router.invalid', true, 0, 2, 0), new \Kadupul\Inventory\Domain\DeviceBulkAssignment('collector', 2));
    }

}
