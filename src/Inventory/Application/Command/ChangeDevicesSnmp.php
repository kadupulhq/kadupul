<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceSnmpSettings;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceSelection;

/**
 * Command that changes SNMP settings on a batch of devices.
 */
final readonly class ChangeDevicesSnmp
{
    /**
     * Binds the command to console access and the SNMP settings port.
     */
    public function __construct(private ConsoleAccess $access, private DeviceSnmpSettings $devices) {}

    /**
     * Applies the change to every device in the selection.
     *
     * @param DeviceSelection $selection Devices to change.
     * @param \Kadupul\Inventory\Domain\DeviceBulkSnmpChange $change Only the selected settings are changed.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(DeviceSelection $selection, \Kadupul\Inventory\Domain\DeviceBulkSnmpChange $change): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $this->devices->changeSnmp($actor->id, $selection, $change);
    }
}
