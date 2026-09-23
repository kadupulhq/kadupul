<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final class DeviceCollectorAssignment
{
    public function __construct(public readonly int $id, public readonly string $description, private int $collectorId, public readonly int $templateId) {}
    public function collectorId(): int
    {
        return $this->collectorId;
    }
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->collectorId, $this->templateId], JSON_THROW_ON_ERROR));
    }
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
