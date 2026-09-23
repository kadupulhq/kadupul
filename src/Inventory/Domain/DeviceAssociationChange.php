<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class DeviceAssociationChange
{
    public function __construct(public string $kind, public string $operation, public int $targetId)
    {
        if ($kind !== 'graph' || !in_array($operation, ['add', 'remove'], true) || $targetId < 1 || $targetId > 16777215) {
            throw new \InvalidArgumentException('Select a valid association change.');
        }
    }
}
