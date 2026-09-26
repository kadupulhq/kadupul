<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class DeviceAssociationChange
{
    public function __construct(public string $kind, public string $operation, public int $targetId, public int $reindexMethod = 0)
    {
        if (!in_array($kind, ['graph', 'query'], true) || !in_array($operation, $kind === 'query' ? ['add', 'remove', 'change'] : ['add', 'remove'], true) || $targetId < 1 || $targetId > 16777215 || $reindexMethod < 0 || $reindexMethod > 3 || ($kind === 'graph' && $reindexMethod !== 0)) {
            throw new \InvalidArgumentException('Select a valid association change.');
        }
    }
}
