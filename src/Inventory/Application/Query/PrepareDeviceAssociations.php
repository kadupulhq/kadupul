<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceAssociationStore;

/**
 * Query that loads a device's associations and the choices for changing them.
 */
final readonly class PrepareDeviceAssociations
{
    /**
     * Binds the query to console access and the association store.
     */
    public function __construct(private ConsoleAccess $access, private DeviceAssociationStore $store) {}
    /**
     * Returns the associations and what can be added.
     *
     * @param string $kind Either "graph" or "query".
     * @return array The default reindex method (0 unless the device supports data query reindexing), the associations (null when the device does not exist or is not visible), and the available targets (empty when the device is null).
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(int $id, string $kind): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $device = $this->store->findVisible($actor->id, $id, $kind);
        return ['default_reindex' => $device !== null && $kind === 'query' && $device->snmpVersion !== 0 ? $this->store->defaultReindexMethod() : 0, 'device' => $device, 'available' => $device === null ? [] : $this->store->available($kind, $device->snmpVersion)];
    }
}
