<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceSelection;

/**
 * Clears the statistics of devices.
 */
interface DeviceStatistics
{
    /**
     * Clears the statistics of every device in the selection.
     */
    public function clearStatistics(int $actorId, DeviceSelection $selection): void;
}
