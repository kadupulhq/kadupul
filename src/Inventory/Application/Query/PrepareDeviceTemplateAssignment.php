<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceTemplateAssignments;

final readonly class PrepareDeviceTemplateAssignment
{
    public function __construct(private ConsoleAccess $access, private DeviceTemplateAssignments $assignments) {}
    public function __invoke(int $id): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        $device = $this->assignments->findVisible($actor->id, $id);
        return ['device' => $device, 'templates' => $device === null ? [] : $this->assignments->templates()];
    }
}
