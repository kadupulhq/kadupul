<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * A request to add, remove or (for data queries) change one graph or data query association on a device.
 */
final readonly class DeviceAssociationChange
{
    /**
     * Validates the change.
     *
     * @param string $kind Either "graph" or "query".
     * @param string $operation "add" or "remove"; "change" is also allowed for queries.
     * @param int $targetId Id of the graph or query (1 to 16777215).
     * @param int $reindexMethod Reindex method 0 to 3; must be 0 for graphs.
     * @throws \InvalidArgumentException When the combination is not supported.
     */
    public function __construct(public string $kind, public string $operation, public int $targetId, public int $reindexMethod = 0)
    {
        if (!in_array($kind, ['graph', 'query'], true) || !in_array($operation, $kind === 'query' ? ['add', 'remove', 'change'] : ['add', 'remove'], true) || $targetId < 1 || $targetId > 16777215 || $reindexMethod < 0 || $reindexMethod > 3 || ($kind === 'graph' && $reindexMethod !== 0)) {
            throw new \InvalidArgumentException('Select a valid association change.');
        }
    }
}
