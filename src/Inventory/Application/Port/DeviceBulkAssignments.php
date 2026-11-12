<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceBulkAssignment;
use Kadupul\Inventory\Domain\DeviceSelection;

/**
 * Assigns many devices to a site, template or collector in one operation.
 */
interface DeviceBulkAssignments
{
    /**
     * Applies the assignment to every device in the selection.
     */
    public function assign(int $actorId, DeviceSelection $selection, DeviceBulkAssignment $assignment): void;
}
