<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Domain\DeviceState;

/**
 * Looks up devices for an enable or disable action and applies it.
 */
interface DeviceStates
{
    /** @return list<DeviceState> All selected visible devices, or DevicesNotFound. */
    public function findVisible(int $actorId, array $ids): array;
    /**
     * Enables or disables every device in the selection.
     */
    public function setEnabled(int $actorId, DeviceSelection $selection, bool $enabled): void;
}
