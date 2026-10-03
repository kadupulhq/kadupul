<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceCollectorAssignments;
use Kadupul\Inventory\Application\Port\DeviceTemplateAssignments;
use Kadupul\Inventory\Application\Port\SiteAssignmentCatalog;

final readonly class ListDeviceAssignmentTargets
{
    public function __construct(private ConsoleAccess $access, private DeviceCollectorAssignments $collectors, private DeviceTemplateAssignments $templates, private SiteAssignmentCatalog $sites) {}
    public function __invoke(string $kind): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return match ($kind) {
            'collector' => $this->collectors->collectors(),
            'template' => $this->templates->templates(),
            'site' => $this->sites->sites(),
            default => throw new \InvalidArgumentException('Invalid assignment kind.'),
        };
    }
}
