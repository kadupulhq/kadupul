<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceCollectorAssignment;

/**
 * Reads and saves the collector a device is assigned to.
 */
interface DeviceCollectorAssignments
{
    /**
     * Loads the device's collector assignment.
     *
     * @return ?DeviceCollectorAssignment Null when the device does not exist or is not visible to the actor.
     */
    public function findVisible(int $actorId, int $deviceId): ?DeviceCollectorAssignment;
    /** @return array<int, string> */
    public function collectors(): array;
    /**
     * Stores the new assignment.
     *
     * @param string $revision Token from the assignment when it was loaded.
     */
    public function save(int $actorId, DeviceCollectorAssignment $assignment, string $revision): void;
}
