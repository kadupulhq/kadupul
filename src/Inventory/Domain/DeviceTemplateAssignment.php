<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final class DeviceTemplateAssignment
{
    public function __construct(public readonly int $id, public readonly string $description, private int $templateId, public readonly int $pollerId) {}
    public function templateId(): int
    {
        return $this->templateId;
    }
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->templateId, $this->pollerId], JSON_THROW_ON_ERROR));
    }
    public function assign(int $templateId, string $revision): void
    {
        if (!hash_equals($this->revision(), $revision)) {
            throw new DeviceEditConflict('This device changed. Reload it before saving.');
        }
        if ($templateId < 0 || $templateId > 16777215) {
            throw new \InvalidArgumentException('Select a valid device template.');
        }
        $this->templateId = $templateId;
    }
}
