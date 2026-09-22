<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Domain\DeviceRemoval;
use Kadupul\Inventory\Domain\DeviceRemovalPolicy;

interface DeviceRemovals
{
    /** @return list<DeviceRemoval> All selected visible devices, or DevicesNotFound. */
    public function findVisible(int $actorId, array $ids): array;
    public function remove(int $actorId, DeviceSelection $selection, DeviceRemovalPolicy $policy): void;
}
