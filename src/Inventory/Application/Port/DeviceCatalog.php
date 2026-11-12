<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceListCriteria;
use Kadupul\Inventory\Application\ReadModel\DevicePage;

/**
 * Lists the devices a user may see.
 */
interface DeviceCatalog
{
    /**
     * Returns one page of the devices visible to the user that match the criteria.
     */
    public function visibleTo(int $userId, DeviceListCriteria $criteria): DevicePage;
}
