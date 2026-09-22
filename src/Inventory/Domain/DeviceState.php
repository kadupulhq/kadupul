<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class DeviceState
{
    public function __construct(public int $id, public string $description, public string $hostname, public bool $enabled, public int $siteId, public int $pollerId, public int $templateId) {}
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->description, $this->hostname, $this->enabled, $this->siteId, $this->pollerId, $this->templateId], JSON_THROW_ON_ERROR));
    }
    public function assertRevision(string $expected): void
    {
        if (!hash_equals($this->revision(), $expected)) {
            throw new DeviceEditConflict('Selected devices changed. Reload the confirmation before saving.');
        }
    }
}
