<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class DeviceMaintenanceState
{
    public function __construct(public DeviceState $device, public array $queries, public array $methods, public bool $debugEnabled) {}
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->device->revision(), $this->queries, $this->methods, $this->debugEnabled], JSON_THROW_ON_ERROR));
    }
    public function assertRequest(DeviceMaintenanceRequest $request, string $revision): void
    {
        if (!hash_equals($this->revision(), $revision)) {
            throw new DeviceEditConflict('This device changed. Reload it before saving.');
        }
        if ($request->queryId > 0 && !array_key_exists($request->queryId, $this->queries)) {
            throw new \InvalidArgumentException('Select an associated data query.');
        }
        if (!$this->device->enabled && in_array($request->operation, ['reindex', 'reload-query', 'query-diagnostics'], true)) {
            throw new \InvalidArgumentException('Enable the device before reindexing its queries.');
        }
    }
}
