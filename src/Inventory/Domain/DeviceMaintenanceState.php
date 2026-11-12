<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * A snapshot of a device and its data queries, used to check a maintenance request against current state.
 */
final readonly class DeviceMaintenanceState
{
    /**
     * Captures the device state for one maintenance request.
     *
     * @param array $queries Data queries associated with the device, keyed by query id.
     * @param array $methods Reindex methods for those queries.
     */
    public function __construct(public DeviceState $device, public array $queries, public array $methods, public bool $debugEnabled) {}
    /**
     * Returns a SHA-256 token over the snapshot, used to detect concurrent edits.
     */
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->device->revision(), $this->queries, $this->methods, $this->debugEnabled], JSON_THROW_ON_ERROR));
    }
    /**
     * Checks that a request applies to this snapshot.
     *
     * @throws DeviceEditConflict When the snapshot changed since $revision was issued.
     * @throws \InvalidArgumentException When the query is not associated with the device, or a reindex action targets a disabled device.
     */
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
