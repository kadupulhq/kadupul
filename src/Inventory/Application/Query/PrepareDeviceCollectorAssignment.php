<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceCollectorAssignments;

/**
 * Query that loads a device's collector assignment and the collectors to choose from.
 */
final readonly class PrepareDeviceCollectorAssignment
{
    /**
     * Binds the query to console access and the collector assignment port.
     */
    public function __construct(private ConsoleAccess $access, private DeviceCollectorAssignments $assignments) {}
    /**
     * Returns the assignment and the collectors.
     *
     * @return array The assignment (null when the device does not exist or is not visible) and the collectors keyed by id (empty when the device is null).
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(int $id): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $device = $this->assignments->findVisible($actor->id, $id);
        return ['device' => $device, 'collectors' => $device === null ? [] : $this->assignments->collectors()];
    }
}
