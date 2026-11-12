<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceMaintenance;
use Kadupul\Inventory\Domain\DeviceMaintenanceState;

/**
 * Query that loads the maintenance state of one device.
 */
final readonly class PrepareDeviceMaintenance
{
    /**
     * Binds the query to console access and the device maintenance port.
     */
    public function __construct(private ConsoleAccess $access, private DeviceMaintenance $maintenance) {}
    /**
     * Returns the state.
     *
     * @return ?DeviceMaintenanceState Null when the device does not exist or is not visible to the user.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(int $id): ?DeviceMaintenanceState
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return $this->maintenance->findVisible($actor->id, $id);
    }
}
