<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Graphing\Contract\DeviceTreePlacement;
use Kadupul\Reporting\Contract\DeviceReportPlacement;

final readonly class ListDevicePlacementDestinations
{
    public function __construct(private ConsoleAccess $access, private DeviceTreePlacement $trees, private DeviceReportPlacement $reports) {}
    public function reportDefaults(): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return ['timespan' => $this->reports->defaultTimespan($actor->id), 'alignment' => 2];
    }
    public function __invoke(string $kind): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return match ($kind) {
            'tree' => $this->trees->destinations($actor->id),
            'report' => $this->reports->destinations($actor->id),
            default => throw new \InvalidArgumentException('Invalid placement kind.'),
        };
    }
}
