<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Collection\Application\Port\PollerCacheStore;
use Kadupul\Collection\Domain\CollectorId;

/**
 * Invalidates poller_item cache rows and marks remote pollers for sync via DBAL.
 */
final readonly class DbalPollerCacheInvalidator implements PollerCacheStore
{
    public function __construct(private Connection $database) {}

    #[\Override]
    public function removeDeviceItems(int $deviceId, ?CollectorId $collectorId = null): void
    {
        if ($collectorId === null) {
            $this->database->executeStatement('DELETE FROM poller_item WHERE host_id = ?', [$deviceId]);

            return;
        }

        $this->database->executeStatement(
            'DELETE FROM poller_item WHERE host_id = ? AND poller_id = ?',
            [$deviceId, $collectorId->toInt()],
        );
    }

    #[\Override]
    public function removeDataSourceItems(int $localDataId, ?CollectorId $collectorId = null): void
    {
        if ($collectorId === null) {
            $this->database->executeStatement('DELETE FROM poller_item WHERE local_data_id = ?', [$localDataId]);

            return;
        }

        $this->database->executeStatement(
            'DELETE FROM poller_item WHERE local_data_id = ? AND poller_id = ?',
            [$localDataId, $collectorId->toInt()],
        );
    }

    #[\Override]
    public function markCollectorRequiresSync(CollectorId $collectorId): void
    {
        $this->database->executeStatement(
            "UPDATE poller SET requires_sync = 'on' WHERE id = ?",
            [$collectorId->toInt()],
        );
    }
}
