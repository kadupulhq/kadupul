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

/**
 * Command that adds, removes or changes one graph or data query association on a device.
 */
final readonly class ChangeDeviceAssociation
{
    /**
     * Binds the command to console access and the association store.
     */
    public function __construct(private ConsoleAccess $access, private DeviceAssociationStore $store) {}
    /**
     * Applies the change after checking it against the device's current associations.
     *
     * @param int $id Device id.
     * @param string $revision Token from the associations when they were loaded.
     * @throws \Kadupul\Inventory\Domain\DeviceEditConflict When the associations changed since $revision was issued.
     * @throws \InvalidArgumentException When the change does not fit the current associations.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices, or the device is not visible to the user.
     */
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
