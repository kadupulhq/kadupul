<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceOptions;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceSelection;

final readonly class ChangeDeviceOptions
{
    public function __construct(private ConsoleAccess $access, private DeviceOptions $devices) {}

    public function __invoke(DeviceSelection $selection, \Kadupul\Inventory\Domain\DeviceOptionsChange $change): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $this->devices->changeOptions($actor->id, $selection, $change);
    }
}
