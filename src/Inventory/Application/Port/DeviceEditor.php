<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\Device;

interface DeviceEditor
{
    public function findVisible(int $userId, int $id): ?Device;
    public function save(int $userId, Device $device, string $expectedRevision): void;
}
