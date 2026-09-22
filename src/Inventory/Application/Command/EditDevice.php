<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceEditor;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;

final readonly class EditDevice
{
    public function __construct(private ConsoleAccess $access, private DeviceEditor $devices) {}
    public function __invoke(int $id, string $description, string $hostname, string $notes, bool $enabled, string $location, string $externalId, string $revision, ?int $siteId = null, ?array $polling = null): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $device = $this->devices->findVisible($actor->id, $id);
        if ($device === null) {
            throw new InventoryAccessDenied(false);
        }
        $device->revise($description, $hostname, $notes, $enabled, $location, $externalId, $revision, $siteId, $polling);
        $this->devices->save($actor->id, $device, $revision);
    }
}
