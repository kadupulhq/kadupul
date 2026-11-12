<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceTemplateAssignments;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;

/**
 * Command that assigns one device to a device template.
 */
final readonly class AssignDeviceTemplate
{
    /**
     * Binds the command to console access and the template assignment port.
     */
    public function __construct(private ConsoleAccess $access, private DeviceTemplateAssignments $assignments) {}
    /**
     * Assigns the template to the device.
     *
     * @param int $id Device id.
     * @param int $templateId Id of the template to assign; 0 means none.
     * @param string $revision Token from the assignment when it was loaded.
     * @throws \Kadupul\Inventory\Domain\DeviceEditConflict When the assignment changed since $revision was issued.
     * @throws \InvalidArgumentException When the template id is out of range.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices, or the device is not visible to the user.
     */
    public function __invoke(int $id, int $templateId, string $revision): void
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $device = $this->assignments->findVisible($actor->id, $id);
        if ($device === null) {
            throw new InventoryAccessDenied(false);
        }
        $device->assign($templateId, $revision);
        $this->assignments->save($actor->id, $device, $revision);
    }
}
