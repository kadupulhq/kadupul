<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceAssociationStore;

final readonly class PrepareDeviceAssociations
{
    public function __construct(private ConsoleAccess $access, private DeviceAssociationStore $store) {}
    public function __invoke(int $id, string $kind): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $device = $this->store->findVisible($actor->id, $id, $kind);
        return ['device' => $device, 'available' => $device === null ? [] : $this->store->available($kind)];
    }
}
