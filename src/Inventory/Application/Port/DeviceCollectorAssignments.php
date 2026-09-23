<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceCollectorAssignment;

interface DeviceCollectorAssignments
{
    public function findVisible(int $actorId, int $deviceId): ?DeviceCollectorAssignment;
    /** @return array<int, string> */
    public function collectors(): array;
    public function save(int $actorId, DeviceCollectorAssignment $assignment, string $revision): void;
}
