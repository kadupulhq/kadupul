<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceBulkAssignments;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceBulkAssignment;
use Kadupul\Inventory\Domain\DeviceSelection;

final readonly class AssignDevices
{
    public function __construct(private ConsoleAccess $access, private DeviceBulkAssignments $devices) {}
    public function __invoke(DeviceSelection $selection, DeviceBulkAssignment $assignment): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $this->devices->assign($actor->id, $selection, $assignment);
    }
}
