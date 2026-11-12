<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceMaintenance;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceMaintenanceRequest;
use Kadupul\Inventory\Application\ReadModel\DeviceMaintenanceResult;

/**
 * Command that runs a maintenance action on one device.
 */
final readonly class MaintainDevice
{
    /**
     * Binds the command to console access and the device maintenance port.
     */
    public function __construct(private ConsoleAccess $access, private DeviceMaintenance $maintenance) {}
    /**
     * Runs the action after checking it against the device's current state.
     *
     * @param int $id Device id.
     * @param string $revision Token from the maintenance state when it was loaded.
     * @throws \Kadupul\Inventory\Domain\DeviceEditConflict When the state changed since $revision was issued.
     * @throws \InvalidArgumentException When the action does not fit the device.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices, or the device is not visible to the user.
     */
    public function __invoke(int $id, DeviceMaintenanceRequest $request, string $revision): DeviceMaintenanceResult
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $state = $this->maintenance->findVisible($actor->id, $id);
        if ($state === null) {
            throw new InventoryAccessDenied(false);
        }
        $state->assertRequest($request, $revision);
        return $this->maintenance->execute($actor->id, $id, $request, $revision, $state);
    }
}
