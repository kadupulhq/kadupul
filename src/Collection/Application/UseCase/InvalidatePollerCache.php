<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\UseCase;

use Kadupul\Collection\Application\Port\PollerCacheStore;
use Kadupul\Collection\Contract\PollerCacheInvalidator;
use Kadupul\Collection\Domain\CollectorId;

final readonly class InvalidatePollerCache implements PollerCacheInvalidator
{
    public function __construct(private PollerCacheStore $store) {}

    #[\Override]
    public function invalidateDevice(int $deviceId, ?int $collectorId = null): void
    {
        if ($deviceId < 1) {
            throw new \InvalidArgumentException('Device id must be positive.');
        }

        $targetCollector = $collectorId === null ? null : CollectorId::fromInt($collectorId);
        $this->store->removeDeviceItems($deviceId, $targetCollector);
        if ($targetCollector !== null && !$targetCollector->isPrimary()) {
            $this->store->markCollectorRequiresSync($targetCollector);
        }
    }

    #[\Override]
    public function invalidateDataSource(int $localDataId, ?int $collectorId = null): void
    {
        if ($localDataId < 1) {
            throw new \InvalidArgumentException('Data source id must be positive.');
        }

        $targetCollector = $collectorId === null ? null : CollectorId::fromInt($collectorId);
        $this->store->removeDataSourceItems($localDataId, $targetCollector);
        if ($targetCollector !== null && !$targetCollector->isPrimary()) {
            $this->store->markCollectorRequiresSync($targetCollector);
        }
    }

    #[\Override]
    public function invalidateCollector(int $collectorId): void
    {
        $this->store->markCollectorRequiresSync(CollectorId::fromInt($collectorId));
    }
}
