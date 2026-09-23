<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceBulkAssignment;
use Kadupul\Inventory\Domain\DeviceSelection;

interface DeviceBulkAssignments
{
    public function assign(int $actorId, DeviceSelection $selection, DeviceBulkAssignment $assignment): void;
}
