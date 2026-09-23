<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class DeviceAssociations
{
    public function __construct(public int $id, public string $description, public int $siteId, public int $pollerId, public int $templateId, public array $items) {}
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->description, $this->siteId, $this->pollerId, $this->templateId, $this->items], JSON_THROW_ON_ERROR));
    }
    public function assertChange(DeviceAssociationChange $change, string $revision): void
    {
        if (!hash_equals($this->revision(), $revision)) {
            throw new DeviceEditConflict('This device changed. Reload it before saving.');
        }
        if (($change->operation === 'remove') !== array_key_exists($change->targetId, $this->items)) {
            throw new \InvalidArgumentException('Select a valid association change.');
        }
    }
}
