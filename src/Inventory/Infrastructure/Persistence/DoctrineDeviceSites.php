<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Inventory\Application\Port\DeviceSites;
use Kadupul\Inventory\Application\ReadModel\DeviceSite;

/**
 * Lists the sites that have at least one device visible to the user.
 */
final readonly class DoctrineDeviceSites implements DeviceSites
{
    /**
     * Binds the reader to the main connection and the visibility rules.
     */
    public function __construct(private Connection $database, private DoctrineDeviceVisibility $visibility) {}

    /**
     * Returns the sites with a visible device, ordered by name.
     */
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
