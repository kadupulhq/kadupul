<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Inventory\Application\Port\DeviceDetailsReader;
use Kadupul\Inventory\Application\ReadModel\DeviceDetails;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceProjection;

final readonly class DoctrineDeviceDetailsReader implements DeviceDetailsReader
{
    public function __construct(private Connection $database, private DoctrineDeviceVisibility $visibility) {}

    public function findVisible(int $userId, int $id): ?DeviceDetails
    {
        $row = $this->database->fetchAssociative("SELECT DISTINCT h.id, h.description, h.hostname,
            h.disabled, h.status, h.location, h.external_id, h.notes, h.site_id, s.name AS site_name
            FROM host h LEFT JOIN sites s ON s.id = h.site_id
            LEFT JOIN graph_local gl ON gl.host_id = h.id
            WHERE h.id = ? AND h.id > 0 AND h.deleted = '' AND (" . $this->visibility->predicate($userId) . ')', [$id]);

        return $row ? new DeviceDetails(LegacyDeviceProjection::summary($row), (string) $row['notes'], (int) $row['site_id'], $row['site_name']) : null;
    }
}
