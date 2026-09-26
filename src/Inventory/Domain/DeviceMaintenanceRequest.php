<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class DeviceMaintenanceRequest
{
    public const OPERATIONS = ['reindex', 'reload-query', 'query-diagnostics', 'refresh-cache', 'enable-debug', 'disable-debug', 'connectivity'];
    public function __construct(public string $operation, public int $queryId = 0)
    {
        $queryOperation = in_array($operation, ['reload-query', 'query-diagnostics'], true);
        if (!in_array($operation, self::OPERATIONS, true) || $queryId < 0 || $queryId > 16777215 || ($queryOperation ? $queryId === 0 : $queryId !== 0)) {
            throw new \InvalidArgumentException('Select a valid device maintenance action and query.');
        }
    }
}
