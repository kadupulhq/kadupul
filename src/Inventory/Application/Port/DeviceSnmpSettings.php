<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceSelection;

/**
 * Changes SNMP settings on many devices at once.
 */
interface DeviceSnmpSettings
{
    /**
     * Applies the change to every device in the selection.
     */
    public function changeSnmp(int $actorId, DeviceSelection $selection, \Kadupul\Inventory\Domain\DeviceBulkSnmpChange $change): void;
}
