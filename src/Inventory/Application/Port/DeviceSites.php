<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Application\ReadModel\DeviceSite;

interface DeviceSites
{
    /** @return list<DeviceSite> */
    public function visibleTo(int $userId): array;
}
