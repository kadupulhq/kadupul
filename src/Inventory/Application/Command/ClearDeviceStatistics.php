<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceStatistics;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceSelection;

/**
 * Command that clears the statistics of a batch of devices.
 */
final readonly class ClearDeviceStatistics
{
    /**
     * Binds the command to console access and the device statistics port.
     */
    public function __construct(private ConsoleAccess $access, private DeviceStatistics $devices) {}

    /**
     * Clears the statistics of every device in the selection.
     *
     * @param DeviceSelection $selection Devices to process.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(DeviceSelection $selection): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $this->devices->clearStatistics($actor->id, $selection);
    }
}
