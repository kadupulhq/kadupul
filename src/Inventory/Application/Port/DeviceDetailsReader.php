<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Application\ReadModel\DeviceDetails;

/**
 * Reads the details of a single device for display.
 */
interface DeviceDetailsReader
{
    /**
     * Returns the device's details, or null when it does not exist or is not visible to the user.
     */
    public function findVisible(int $userId, int $id): ?DeviceDetails;
}
