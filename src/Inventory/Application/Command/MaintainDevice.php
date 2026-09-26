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

final readonly class MaintainDevice
{
    public function __construct(private ConsoleAccess $access, private DeviceMaintenance $maintenance) {}
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
        return $this->maintenance->execute($actor->id, $id, $request, $revision);
    }
}
