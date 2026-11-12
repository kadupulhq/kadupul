<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceCreationCatalog;

/**
 * Query that loads the choices for the device creation form.
 */
final readonly class PrepareDeviceCreation
{
    /**
     * Binds the query to console access and the device creation catalog.
     */
    public function __construct(private ConsoleAccess $access, private DeviceCreationCatalog $catalog) {}

    /**
     * Returns the choices.
     *
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function __invoke(): DeviceCreationChoices
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }

        return $this->catalog->choices();
    }
}
