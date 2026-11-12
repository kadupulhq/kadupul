<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceAutomation;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceSelection;

/**
 * Command that applies the automation rules to a batch of devices.
 */
final readonly class ApplyDeviceRules
{
    /**
     * Binds the command to console access and the device automation port.
     */
    public function __construct(private ConsoleAccess $access, private DeviceAutomation $devices) {}

    /**
     * Applies the rules to every device in the selection.
     *
     * @param DeviceSelection $selection Devices to process.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(DeviceSelection $selection): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $this->devices->applyRules($actor->id, $selection);
    }
}
