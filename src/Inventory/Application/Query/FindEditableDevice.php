<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceEditor;
use Kadupul\Inventory\Domain\Device;

/**
 * Query that loads one device for editing.
 */
final readonly class FindEditableDevice
{
    /**
     * Binds the query to console access and the device editor.
     */
    public function __construct(private ConsoleAccess $access, private DeviceEditor $devices) {}
    /**
     * Returns the device.
     *
     * @return ?Device Null when the device does not exist or is not visible to the user.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(int $id): ?Device
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return $this->devices->findVisible($actor->id, $id);
    }
}
