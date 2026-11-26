<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Kadupul\AssetManagement\Application\Port\MonitoringInventory;
use Kadupul\AssetManagement\Domain\AssetData;
use Kadupul\AssetManagement\Domain\AssetError;
use Kadupul\IdentityAccess\Contract\VisibilitySql;

/**
 * Reads the monitored devices from the host table as asset observations.
 */
final readonly class MonitoringSnapshot implements MonitoringInventory
{
    /**
     * Binds the inventory to the database connection.
     */
    public function __construct(private Connection $database) {}

    /**
     * Returns one observation per visible device id, stamped with $observedAt.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     * @throws AssetError when the actor cannot be scoped or any id is not an existing, undeleted, visible device
     */
    public function observations(int $actorId, array $ids, string $observedAt): array
    {
        if ($actorId < 1) {
            throw new AssetError('denied');
        }
        if ($ids === []) {
            return [];
        }
        $policies = $this->database->fetchAllAssociative(VisibilitySql::POLICIES, [$actorId, $actorId]);
        $user = array_find($policies, static fn(array $policy): bool => $policy['kind'] === 'user');
        if ($user === null) {
            throw new AssetError('denied');
        }
        $mode = VisibilitySql::mode($this->database->fetchOne(VisibilitySql::MODE));
        $predicate = VisibilitySql::predicate($mode, $policies);
        $rows = $this->database->fetchAllAssociative(
            "SELECT DISTINCT h.id, h.description, h.hostname, h.location
             FROM host h
             LEFT JOIN graph_local gl ON gl.host_id = h.id
             WHERE h.deleted = '' AND h.id > 0 AND h.id IN (?) AND ($predicate)
             ORDER BY h.id",
            [$ids],
            [ArrayParameterType::INTEGER],
        );
        if (count($rows) !== count($ids)) {
            throw new AssetError('not_found');
        }
        return array_map(static fn(array $row): array => ['key' => (string) AssetData::number($row['id']), 'observed_at' => $observedAt, 'fields' => ['name' => AssetData::string($row['description']), 'address' => AssetData::string($row['hostname']), 'location' => AssetData::string($row['location'] ?? '')]], $rows);
    }
}
