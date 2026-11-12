<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceStates;
use Kadupul\Inventory\Domain\DeviceSelection;

/**
 * Query that loads the devices selected for an enable or disable action.
 */
final readonly class PrepareDeviceStateChange
{
    /**
     * Binds the query to console access and the device state port.
     */
    public function __construct(private ConsoleAccess $access, private DeviceStates $devices) {}

    /**
     * Returns the current state of the devices for the confirmation step.
     *
     * @param array $ids Devices selected for the change.
     * @return array List of DeviceState.
     * @throws \Kadupul\Inventory\Application\Command\DevicesNotFound When a selected device does not exist or is not visible.
     * @throws \InvalidArgumentException When the ids are not 1 to 100 valid, unique ids.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(array $ids): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return $this->devices->findVisible($actor->id, DeviceSelection::validateIds($ids));
    }
}
