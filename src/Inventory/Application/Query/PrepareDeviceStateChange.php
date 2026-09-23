<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceStates;
use Kadupul\Inventory\Domain\DeviceSelection;

final readonly class PrepareDeviceStateChange
{
    public function __construct(private ConsoleAccess $access, private DeviceStates $devices) {}

    public function __invoke(array $ids): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return $this->devices->findVisible($actor->id, DeviceSelection::validateIds($ids));
    }
}
