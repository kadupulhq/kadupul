<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceDetailsReader;
use Kadupul\Inventory\Application\ReadModel\DeviceDetails;

final readonly class FindDeviceDetails
{
    public function __construct(private ConsoleAccess $access, private DeviceDetailsReader $devices) {}

    public function __invoke(int $id): ?DeviceDetails
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return $this->devices->findVisible($actor->id, $id);
    }
}
