<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * A device selected for removal, with the graphs and data sources that would be affected.
 */
final readonly class DeviceRemoval
{
    /**
     * Captures the device and its dependent graph and data source ids.
     *
     * @param array $graphIds List of int.
     * @param array $dataSourceIds List of int.
     */
    public function __construct(public DeviceState $device, public array $graphIds, public array $dataSourceIds) {}
    /**
     * Returns a SHA-256 token over the device and its dependents, used to detect concurrent edits.
     */
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->device->revision(), $this->graphIds, $this->dataSourceIds], JSON_THROW_ON_ERROR));
    }
    /**
     * Checks that the device, its graphs and its data sources are unchanged since $expected was issued.
     *
     * @throws DeviceEditConflict When anything changed.
     */
    public function assertRevision(string $expected): void
    {
        if (!hash_equals($this->revision(), $expected)) {
            throw new DeviceEditConflict('Selected devices or their graphs and data sources changed. Reload the confirmation before removing devices.');
        }
    }
}
