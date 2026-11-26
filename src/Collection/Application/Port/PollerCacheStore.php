<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\Port;

use Kadupul\Collection\Domain\CollectorId;

interface PollerCacheStore
{
    public function removeDeviceItems(int $deviceId, ?CollectorId $collectorId = null): void;

    public function removeDataSourceItems(int $localDataId, ?CollectorId $collectorId = null): void;

    public function markCollectorRequiresSync(CollectorId $collectorId): void;
}
