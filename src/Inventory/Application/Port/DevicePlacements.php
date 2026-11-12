<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DevicePlacement;
use Kadupul\Inventory\Domain\DeviceSelection;

/**
 * Places devices in graph trees or reports.
 */
interface DevicePlacements
{
    /**
     * Places every device in the selection at the destination.
     */
    public function place(int $actorId, DeviceSelection $selection, DevicePlacement $placement): void;
}
