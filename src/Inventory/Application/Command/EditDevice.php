<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceEditor;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;

/**
 * Command that edits one device.
 */
final readonly class EditDevice
{
    /**
     * Binds the command to console access and the device editor.
     */
    public function __construct(private ConsoleAccess $access, private DeviceEditor $devices) {}
    /**
     * Applies the edit to the device after checking the revision.
     *
     * @param int $id Device id.
     * @param string $revision Token from the device when the form was loaded.
     * @param array|null $polling Polling fields to replace; null keeps the current ones.
     * @param array|null $snmp SNMP fields to replace; null keeps the current ones.
     * @throws \Kadupul\Inventory\Domain\DeviceEditConflict When the device changed since $revision was issued.
     * @throws \InvalidArgumentException When a value is invalid.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices, or the device is not visible to the user.
     */
    public function __invoke(int $id, string $description, string $hostname, string $notes, bool $enabled, string $location, string $externalId, string $revision, ?int $siteId = null, ?array $polling = null, #[\SensitiveParameter] ?array $snmp = null): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $device = $this->devices->findVisible($actor->id, $id);
        if ($device === null) {
            throw new InventoryAccessDenied(false);
        }
        $device->revise($description, $hostname, $notes, $enabled, $location, $externalId, $revision, $siteId, $polling, $snmp);
        $this->devices->save($actor->id, $device, $revision);
    }
}
