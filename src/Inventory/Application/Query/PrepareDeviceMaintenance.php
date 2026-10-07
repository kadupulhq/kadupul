<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceMaintenance;
use Kadupul\Inventory\Domain\DeviceMaintenanceState;

final readonly class PrepareDeviceMaintenance
{
    public function __construct(private ConsoleAccess $access, private DeviceMaintenance $maintenance) {}
    public function __invoke(int $id): ?DeviceMaintenanceState
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return $this->maintenance->findVisible($actor->id, $id);
    }
}
