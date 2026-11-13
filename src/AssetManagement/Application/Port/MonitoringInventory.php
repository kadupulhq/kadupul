<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Application\Port;

/**
 * Source of device observations from the monitoring system.
 */
interface MonitoringInventory
{
    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function observations(array $ids, string $observedAt): array;
}
