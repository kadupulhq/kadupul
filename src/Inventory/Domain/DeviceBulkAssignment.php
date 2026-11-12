<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * A validated target for assigning many devices to a site, template or collector at once.
 */
final readonly class DeviceBulkAssignment
{
    /**
     * Validates the target.
     *
     * @param string $kind One of "site", "template" or "collector".
     * @param int $targetId Target id; 0 is allowed except for collectors.
     * @throws \InvalidArgumentException When the kind is unknown or the id is out of range.
     */
    public function __construct(public string $kind, public int $targetId)
    {
        $maximum = match ($kind) {
            'site' => 4294967295,
            'template', 'collector' => 16777215,
            default => throw new \InvalidArgumentException('Invalid assignment kind.'),
        };
        if ($targetId < ($kind === 'collector' ? 1 : 0) || $targetId > $maximum) {
            throw new \InvalidArgumentException('Select a valid assignment target.');
        }
    }
}
