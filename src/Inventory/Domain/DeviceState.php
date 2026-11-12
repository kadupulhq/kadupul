<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * A snapshot of a device's identity, site, collector, template and options, for checking batch operations against current state.
 */
final readonly class DeviceState
{
    /**
     * Captures the device state.
     *
     * @param array $options Map from string to mixed.
     * @param array $snmp Map from string to mixed.
     */
    public function __construct(public int $id, public string $description, public string $hostname, public bool $enabled, public int $siteId, public int $pollerId, public int $templateId, public array $options = [], public array $snmp = []) {}
    /**
     * Returns a SHA-256 token over the snapshot, used to detect concurrent edits.
     */
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->description, $this->hostname, $this->enabled, $this->siteId, $this->pollerId, $this->templateId, $this->options, $this->snmp], JSON_THROW_ON_ERROR));
    }
    /**
     * Checks that the snapshot is unchanged since $expected was issued.
     *
     * @throws DeviceEditConflict When it changed.
     */
    public function assertRevision(string $expected): void
    {
        if (!hash_equals($this->revision(), $expected)) {
            throw new DeviceEditConflict('Selected devices changed. Reload the confirmation before saving.');
        }
    }
}
