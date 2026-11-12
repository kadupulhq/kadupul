<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * A requested maintenance action on one device, optionally tied to one of its data queries.
 */
final readonly class DeviceMaintenanceRequest
{
    public const OPERATIONS = ['reindex', 'reload-query', 'query-diagnostics', 'refresh-cache', 'enable-debug', 'disable-debug', 'connectivity'];
    /**
     * Validates the action.
     * The "reload-query" and "query-diagnostics" actions require a query id; every other action requires it to be 0.
     *
     * @throws \InvalidArgumentException When the operation is unknown or the query id does not fit the operation.
     */
    public function __construct(public string $operation, public int $queryId = 0)
    {
        $queryOperation = in_array($operation, ['reload-query', 'query-diagnostics'], true);
        if (!in_array($operation, self::OPERATIONS, true) || $queryId < 0 || $queryId > 16777215 || ($queryOperation ? $queryId === 0 : $queryId !== 0)) {
            throw new \InvalidArgumentException('Select a valid device maintenance action and query.');
        }
    }
}
