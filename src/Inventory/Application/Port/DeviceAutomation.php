<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceSelection;

/**
 * Applies automation rules to devices.
 */
interface DeviceAutomation
{
    /**
     * Applies the rules to every device in the selection.
     */
    public function applyRules(int $actorId, DeviceSelection $selection): void;
}
