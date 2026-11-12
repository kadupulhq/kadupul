<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Graphing\Contract\DeviceTreePlacement;
use Kadupul\Reporting\Contract\DeviceReportPlacement;

/**
 * Query that lists where devices can be placed: graph trees or reports.
 */
final readonly class ListDevicePlacementDestinations
{
    /**
     * Binds the query to console access and the tree and report placement catalogs.
     */
    public function __construct(private ConsoleAccess $access, private DeviceTreePlacement $trees, private DeviceReportPlacement $reports) {}
    /**
     * Returns the default report display settings offered when placing devices in a report.
     *
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
    public function reportDefaults(): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new InventoryAccessDenied($actor === null);
        }
        return ['timespan' => $this->reports->defaultTimespan($actor->id), 'alignment' => 2];
    }
    /**
     * Returns the destinations of one kind.
     *
     * @param string $kind Either "tree" or "report".
     * @return array The destinations offered by the matching catalog.
     * @throws \InvalidArgumentException When the kind is not supported.
     * @throws InventoryAccessDenied When nobody is signed in or the user may not manage devices.
     */
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
