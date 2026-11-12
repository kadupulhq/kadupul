<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\Device;

/**
 * Loads and saves devices for editing.
 */
interface DeviceEditor
{
    /**
     * Loads the device, or returns null when it does not exist or is not visible to the user.
     */
    public function findVisible(int $userId, int $id): ?Device;
    /**
     * Stores the edited device.
     *
     * @param string $expectedRevision Token from the device when it was loaded.
     */
    public function save(int $userId, Device $device, string $expectedRevision): void;
}
