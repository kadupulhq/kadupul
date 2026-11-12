<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceOptions;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceSelection;

/**
 * Command that changes the location or polling options of a batch of devices.
 */
final readonly class ChangeDeviceOptions
{
    /**
     * Binds the command to console access and the device options port.
     */
    public function __construct(private ConsoleAccess $access, private DeviceOptions $devices) {}

    /**
     * Applies the change to every device in the selection.
     *
     * @param DeviceSelection $selection Devices to change.
     * @param \Kadupul\Inventory\Domain\DeviceOptionsChange $change Only the fields it carries are changed.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(DeviceSelection $selection, \Kadupul\Inventory\Domain\DeviceOptionsChange $change): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $this->devices->changeOptions($actor->id, $selection, $change);
    }
}
