<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * A device's assignment to a device template, changed through assign() with a revision check.
 */
final class DeviceTemplateAssignment
{
    /**
     * Loads the stored assignment.
     */
    public function __construct(public readonly int $id, public readonly string $description, private int $templateId, public readonly int $pollerId) {}
    /**
     * Returns the id of the assigned template, where 0 means none.
     */
    public function templateId(): int
    {
        return $this->templateId;
    }
    /**
     * Returns a SHA-256 token over the assignment, used to detect concurrent edits.
     */
    public function revision(): string
    {
        return hash('sha256', json_encode([$this->id, $this->templateId, $this->pollerId], JSON_THROW_ON_ERROR));
    }
    /**
     * Moves the device to another template.
     *
     * @throws DeviceEditConflict When the assignment changed since $revision was issued.
     * @throws \InvalidArgumentException When $templateId is outside 0 to 16777215.
     */
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
