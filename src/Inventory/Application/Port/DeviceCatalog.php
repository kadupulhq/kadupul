<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceListCriteria;
use Kadupul\Inventory\Application\ReadModel\DevicePage;

interface DeviceCatalog
{
    public function visibleTo(int $userId, DeviceListCriteria $criteria): DevicePage;
}
