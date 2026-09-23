<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceCollectorAssignments;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;

final readonly class AssignDeviceCollector
{
    public function __construct(private ConsoleAccess $access, private DeviceCollectorAssignments $assignments) {}
    public function __invoke(int $id, int $collectorId, string $revision): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $device = $this->assignments->findVisible($actor->id, $id);
        if ($device === null) {
            throw new InventoryAccessDenied(false);
        }
        $device->assign($collectorId, $revision);
        $this->assignments->save($actor->id, $device, $revision);
    }
}
