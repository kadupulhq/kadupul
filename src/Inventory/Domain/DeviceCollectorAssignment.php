<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * A device's assignment to a collector, changed through assign() with a revision check.
 */
final class DeviceCollectorAssignment
{
    /**
     * Loads the stored assignment.
     */
    public function __construct(public readonly int $id, public readonly string $description, private int $collectorId, public readonly int $templateId) {}
    /**
     * Returns the id of the assigned collector.
     */
    public function collectorId(): int
    {
        return $this->collectorId;
    }
    /**
     * Returns a SHA-256 token over the assignment, used to detect concurrent edits.
     */
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->collectorId, $this->templateId], JSON_THROW_ON_ERROR));
    }
    /**
     * Moves the device to another collector.
     *
     * @throws DeviceEditConflict When the assignment changed since $revision was issued.
     * @throws \InvalidArgumentException When $collectorId is outside 1 to 16777215.
     */
    public function assign(int $collectorId, string $revision): void
    {
        if (!hash_equals($this->revision(), $revision)) {
            throw new DeviceEditConflict('This device changed. Reload it before saving.');
        }
        if ($collectorId < 1 || $collectorId > 16777215) {
            throw new \InvalidArgumentException('Select a valid device collector.');
        }
        $this->collectorId = $collectorId;
    }
}
