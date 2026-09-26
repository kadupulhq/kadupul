<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceAssociationStore;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceAssociationChange;

final readonly class ChangeDeviceAssociation
{
    public function __construct(private ConsoleAccess $access, private DeviceAssociationStore $store) {}
    public function __invoke(int $id, DeviceAssociationChange $change, string $revision): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $device = $this->store->findVisible($actor->id, $id, $change->kind);
        if ($device === null) {
            throw new InventoryAccessDenied(false);
        }
        $device->assertChange($change, $revision);
        $this->store->change($actor->id, $id, $change, $revision);
    }
}
