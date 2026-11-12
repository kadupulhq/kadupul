<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceDetailsReader;
use Kadupul\Inventory\Application\ReadModel\DeviceDetails;

/**
 * Query that loads the details of one device for display.
 */
final readonly class FindDeviceDetails
{
    /**
     * Binds the query to console access and the device details reader.
     */
    public function __construct(private ConsoleAccess $access, private DeviceDetailsReader $devices) {}

    /**
     * Returns the device details.
     *
     * @return ?DeviceDetails Null when the device does not exist or is not visible to the user.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(int $id): ?DeviceDetails
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return $this->devices->findVisible($actor->id, $id);
    }
}
