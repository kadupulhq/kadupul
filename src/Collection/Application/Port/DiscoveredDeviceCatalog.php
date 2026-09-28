<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\Port;

use Kadupul\Collection\Application\ReadModel\DiscoveredDevicePage;
use Kadupul\Collection\Domain\DiscoveredDeviceCriteria;

interface DiscoveredDeviceCatalog
{
    public function list(DiscoveredDeviceCriteria $criteria): DiscoveredDevicePage;
}
