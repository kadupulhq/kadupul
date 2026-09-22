<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceTemplateAssignment;

interface DeviceTemplateAssignments
{
    public function findVisible(int $actorId, int $deviceId): ?DeviceTemplateAssignment;
    /** @return array<int, string> */
    public function templates(): array;
    public function save(int $actorId, DeviceTemplateAssignment $assignment, string $revision): void;
}
