<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\SynchronizeDeviceTemplates;
use Kadupul\Inventory\Application\Port\DeviceTemplateSynchronization;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceSelection;
use PHPUnit\Framework\TestCase;

final class DeviceTemplateSynchronizationTest extends TestCase
{
    public function testAuthorizedSynchronizationPreservesSelectionAndActor(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access->method('canManageDevices')->willReturn(true);
        $selection = new DeviceSelection([7 => str_repeat('a', 64)]);
        $port = $this->createMock(DeviceTemplateSynchronization::class);
        $port->expects(self::once())->method('synchronizeTemplates')->with(42, $selection);
        (new SynchronizeDeviceTemplates($access, $port))($selection);
    }

    public function testAuthorizationPrecedesSynchronization(): void
    {
        foreach ([null, new Actor(42, 'operator')] as $actor) {
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn($actor);
            $access->method('canManageDevices')->willReturn(false);
            $port = $this->createMock(DeviceTemplateSynchronization::class);
            $port->expects(self::never())->method('synchronizeTemplates');
            try {
                (new SynchronizeDeviceTemplates($access, $port))(new DeviceSelection([7 => str_repeat('a', 64)]));
                self::fail('Unauthorized reset accepted');
            } catch (InventoryAccessDenied $error) {
                self::assertSame($actor === null, $error->unauthenticated);
            }
        }
    }
}
