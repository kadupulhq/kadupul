<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Inventory\Application\Port\DeviceSites;
use Kadupul\Inventory\Application\ReadModel\DeviceSite;

final readonly class DoctrineDeviceSites implements DeviceSites
{
    public function __construct(private Connection $database, private DoctrineDeviceVisibility $visibility) {}

    public function visibleTo(int $userId): array
    {
        $predicate = $this->visibility->predicate($userId);
        $rows = $this->database->fetchAllAssociative("SELECT DISTINCT s.id, s.name
            FROM sites s JOIN host h ON h.site_id = s.id
            LEFT JOIN graph_local gl ON gl.host_id = h.id
            WHERE s.id > 0 AND h.id > 0 AND h.deleted = '' AND ($predicate)
            ORDER BY s.name ASC, s.id ASC");
        $sites = [];
        foreach ($rows as $row) {
            $sites[] = new DeviceSite((int) $row['id'], $row['name']);
        }

        return $sites;
    }
}
