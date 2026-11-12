<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceMaintenanceState;
use Kadupul\Inventory\Domain\DeviceMaintenanceRequest;
use Kadupul\Inventory\Application\ReadModel\DeviceMaintenanceResult;

/**
 * Reads the maintenance state of a device and runs maintenance actions on it.
 */
interface DeviceMaintenance
{
    /**
     * Returns the device's maintenance state, or null when it does not exist or is not visible to the actor.
     */
    public function findVisible(int $actorId, int $id): ?DeviceMaintenanceState;
    /**
     * Runs a requested action that has already been checked against $state.
     *
     * @param string $revision Token from the maintenance state when it was loaded.
     */
    public function execute(int $actorId, int $id, DeviceMaintenanceRequest $request, string $revision, DeviceMaintenanceState $state): DeviceMaintenanceResult;
}
