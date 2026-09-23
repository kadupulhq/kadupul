<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\ChangeDevicesSnmp;
use Kadupul\Inventory\Application\Port\DeviceSnmpSettings;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceSelection;
use PHPUnit\Framework\TestCase;

final class DeviceBulkSnmpTest extends TestCase
{
    public function testAuthorizedResetPreservesSelectionAndActor(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access->method('canManageDevices')->willReturn(true);
        $selection = new DeviceSelection([7 => str_repeat('a', 64)]);
        $port = $this->createMock(DeviceSnmpSettings::class);
        $port->expects(self::once())->method('changeSnmp')->with(42, $selection, self::callback(fn($change) => $change->fields['keep_credentials'] === true));
        (new ChangeDevicesSnmp($access, $port))($selection, new \Kadupul\Inventory\Domain\DeviceSnmpChange(['keep_credentials' => true] + \Kadupul\Inventory\Domain\DeviceSnmpConfiguration::PUBLIC_DEFAULTS + \Kadupul\Inventory\Domain\DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS));
    }

    public function testAuthorizationPrecedesReset(): void
    {
        foreach ([null, new Actor(42, 'operator')] as $actor) {
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn($actor);
            $access->method('canManageDevices')->willReturn(false);
            $port = $this->createMock(DeviceSnmpSettings::class);
            $port->expects(self::never())->method('changeSnmp');
            try {
                (new ChangeDevicesSnmp($access, $port))(new DeviceSelection([7 => str_repeat('a', 64)]), new \Kadupul\Inventory\Domain\DeviceSnmpChange(['keep_credentials' => true] + \Kadupul\Inventory\Domain\DeviceSnmpConfiguration::PUBLIC_DEFAULTS + \Kadupul\Inventory\Domain\DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS));
                self::fail('Unauthorized reset accepted');
            } catch (InventoryAccessDenied $error) {
                self::assertSame($actor === null, $error->unauthenticated);
            }
        }
    }
    public function testPublicProtocolChangesInvalidateConfirmation(): void
    {
        $before = new \Kadupul\Inventory\Domain\DeviceState(1, 'Router', 'router.invalid', true, 0, 1, 0, [], ['snmp_version' => '2']);
        $after = new \Kadupul\Inventory\Domain\DeviceState(1, 'Router', 'router.invalid', true, 0, 1, 0, [], ['snmp_version' => '3']);
        $this->expectException(\Kadupul\Inventory\Domain\DeviceEditConflict::class);
        $after->assertRevision($before->revision());
    }
}
