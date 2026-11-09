<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Infrastructure\Persistence;

use Kadupul\AssetManagement\Domain\AssetData;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Kadupul\AssetManagement\Application\Port\MonitoringInventory;
use Kadupul\AssetManagement\Domain\AssetError;

final readonly class MonitoringSnapshot implements MonitoringInventory
{
    public function __construct(private Connection $database) {}

    public function observations(array $ids, string $observedAt): array
    {
        $rows = $this->database->fetchAllAssociative("SELECT id, description, hostname, location FROM host WHERE deleted = '' AND id IN (?) ORDER BY id", [$ids], [ArrayParameterType::INTEGER]);
        if (count($rows) !== count($ids)) {
            throw new AssetError('not_found');
        }
        return array_map(static fn(array $row): array => ['key' => (string) AssetData::number($row['id']), 'observed_at' => $observedAt, 'fields' => ['name' => AssetData::string($row['description']), 'address' => AssetData::string($row['hostname']), 'location' => AssetData::string($row['location'] ?? '')]], $rows);
    }
}
