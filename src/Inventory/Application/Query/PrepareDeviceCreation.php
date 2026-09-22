<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceCreationCatalog;

final readonly class PrepareDeviceCreation
{
    public function __construct(private ConsoleAccess $access, private DeviceCreationCatalog $catalog) {}

    public function __invoke(): DeviceCreationChoices
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }

        return $this->catalog->choices();
    }
}
