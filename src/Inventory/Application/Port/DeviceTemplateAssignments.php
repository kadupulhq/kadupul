<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceTemplateAssignment;

/**
 * Reads and saves the template a device is assigned to.
 */
interface DeviceTemplateAssignments
{
    /**
     * Loads the device's template assignment.
     *
     * @return ?DeviceTemplateAssignment Null when the device does not exist or is not visible to the actor.
     */
    public function findVisible(int $actorId, int $deviceId): ?DeviceTemplateAssignment;
    /** @return array<int, string> */
    public function templates(): array;
    /**
     * Stores the new assignment.
     *
     * @param string $revision Token from the assignment when it was loaded.
     */
    public function save(int $actorId, DeviceTemplateAssignment $assignment, string $revision): void;
}
