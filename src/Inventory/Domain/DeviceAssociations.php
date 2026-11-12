<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * A snapshot of a device's graph or data query associations, used to check a requested change against current state.
 */
final readonly class DeviceAssociations
{
    /**
     * Captures the associations of one device.
     *
     * @param array $items Existing associations keyed by target id.
     * @param array $methods Reindex methods for the existing associations.
     * @param string $kind Either "graph" or "query".
     */
    public function __construct(public int $id, public string $description, public int $siteId, public int $pollerId, public int $templateId, public array $items, public array $methods = [], public string $kind = 'graph', public int $snmpVersion = 2) {}
    /**
     * Returns a SHA-256 token over the snapshot, used to detect concurrent edits.
     */
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->description, $this->siteId, $this->pollerId, $this->templateId, $this->items, $this->methods, $this->kind, $this->snmpVersion], JSON_THROW_ON_ERROR));
    }
    /**
     * Checks that a change matches this snapshot's kind and current state.
     *
     * @throws DeviceEditConflict When the snapshot changed since $revision was issued.
     * @throws \InvalidArgumentException When the kind does not match, or the target is already present (add) or missing (remove or change).
     */
    public function assertChange(DeviceAssociationChange $change, string $revision): void
    {
        if ($change->kind !== $this->kind || ($change->kind === 'query' && $change->operation !== 'remove' && $change->reindexMethod === 1 && $this->snmpVersion === 0)) {
            throw new \InvalidArgumentException('Select a valid association change.');
        }
        if (!hash_equals($this->revision(), $revision)) {
            throw new DeviceEditConflict('This device changed. Reload it before saving.');
        }
        if (($change->operation !== 'add') !== array_key_exists($change->targetId, $this->items)) {
            throw new \InvalidArgumentException('Select a valid association change.');
        }
    }
}
