<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DevicePlacements;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DevicePlacement;
use Kadupul\Inventory\Domain\DeviceSelection;

/**
 * Command that places a batch of devices in a graph tree or report.
 */
final readonly class PlaceDevices
{
    /**
     * Binds the command to console access and the device placement port.
     */
    public function __construct(private ConsoleAccess $access, private DevicePlacements $devices) {}
    /**
     * Places every device in the selection at the destination.
     *
     * @param DeviceSelection $selection Devices to place.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(DeviceSelection $selection, DevicePlacement $placement): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $this->devices->place($actor->id, $selection, $placement);
    }
}
