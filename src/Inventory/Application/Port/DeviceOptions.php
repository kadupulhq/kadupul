<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceSelection;

interface DeviceOptions
{
    public function changeOptions(int $actorId, DeviceSelection $selection, \Kadupul\Inventory\Domain\DeviceOptionsChange $change): void;
}
