<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class DeviceRemoval
{
    public function __construct(public DeviceState $device, public array $graphIds, public array $dataSourceIds) {}
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->device->revision(), $this->graphIds, $this->dataSourceIds], JSON_THROW_ON_ERROR));
    }
    public function assertRevision(string $expected): void
    {
        if (!hash_equals($this->revision(), $expected)) {
            throw new DeviceEditConflict('Selected devices or their graphs and data sources changed. Reload the confirmation before removing devices.');
        }
    }
}
