<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

use Kadupul\Inventory\Domain\DeviceAssociations;
use Kadupul\Inventory\Domain\DeviceAssociationChange;

interface DeviceAssociationStore
{
    public function findVisible(int $actorId, int $id, string $kind): ?DeviceAssociations;
    public function defaultReindexMethod(): int;
    public function available(string $kind): array;
    public function change(int $actorId, int $id, DeviceAssociationChange $change, string $revision): void;
}
