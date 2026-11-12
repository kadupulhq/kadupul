<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceCreator;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\NewDevice;

/**
 * Command that creates a device.
 */
final readonly class CreateDevice
{
    /**
     * Binds the command to console access and the device creator.
     */
    public function __construct(private ConsoleAccess $access, private DeviceCreator $devices) {}

    /**
     * Validates the fields and creates the device.
     *
     * @param array $fields Submitted values; see NewDevice::DEFAULTS for the keys.
     * @return int Id of the new device.
     * @throws \InvalidArgumentException When a field is invalid.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(#[\SensitiveParameter] array $fields): int
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }

        return $this->devices->create($actor->id, new NewDevice($fields));
    }
}
