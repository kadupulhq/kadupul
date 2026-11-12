<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceRemovals;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Domain\DeviceRemovalPolicy;

/**
 * Command that removes a batch of devices.
 */
final readonly class RemoveDevices
{
    /**
     * Binds the command to console access and the device removal port.
     */
    public function __construct(private ConsoleAccess $access, private DeviceRemovals $devices) {}

    /**
     * Removes every device in the selection, handling their graphs and data sources as the policy says.
     *
     * @param DeviceSelection $selection Devices to remove.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(DeviceSelection $selection, DeviceRemovalPolicy $policy): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $this->devices->remove($actor->id, $selection, $policy);
    }
}
