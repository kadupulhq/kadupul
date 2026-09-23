<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceSnmpSettings;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceSelection;

final readonly class ChangeDevicesSnmp
{
    public function __construct(private ConsoleAccess $access, private DeviceSnmpSettings $devices) {}

    public function __invoke(DeviceSelection $selection, \Kadupul\Inventory\Domain\DeviceSnmpChange $change): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $this->devices->changeSnmp($actor->id, $selection, $change);
    }
}
