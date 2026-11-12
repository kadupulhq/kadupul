<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceCatalog;
use Kadupul\Inventory\Application\ReadModel\DevicePage;
use Kadupul\Inventory\Domain\DeviceListCriteria;

/**
 * Query that returns one page of the device list.
 */
final readonly class ListDevices
{
    /**
     * Binds the query to console access and the device catalog.
     */
    public function __construct(private ConsoleAccess $access, private DeviceCatalog $devices) {}

    /**
     * Returns the devices visible to the user that match the criteria.
     *
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(DeviceListCriteria $criteria): DevicePage
    {
        $actor = $this->access->consoleActor();
        if ($actor === null) {
            throw new InventoryAccessDenied(true);
        }
        if (!$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied(false);
        }

        return $this->devices->visibleTo($actor->id, $criteria);
    }
}
