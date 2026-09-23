<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceMaintenanceState;
use Kadupul\Inventory\Domain\DeviceMaintenanceRequest;
use Kadupul\Inventory\Application\ReadModel\DeviceMaintenanceResult;

interface DeviceMaintenance
{
    public function findVisible(int $actorId, int $id): ?DeviceMaintenanceState;
    public function execute(int $actorId, int $id, DeviceMaintenanceRequest $request, string $revision): DeviceMaintenanceResult;
}
