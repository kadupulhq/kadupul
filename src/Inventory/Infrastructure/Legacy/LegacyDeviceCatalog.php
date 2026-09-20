<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceCatalog;
use Kadupul\Inventory\Application\ReadModel\DevicePage;
use Kadupul\Inventory\Application\ReadModel\DeviceSummary;
use Kadupul\Inventory\Domain\DeviceListCriteria;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacyDeviceCatalog implements DeviceCatalog
{
    public function __construct(private DatabaseConnection $database, private LegacyDeviceVisibility $visibility) {}

    public function visibleTo(int $userId, DeviceListCriteria $criteria): DevicePage
    {
        $db = $this->database->get();
        $where = "h.id > 0 AND h.deleted = ''";
        $parameters = [];
        if ($criteria->search !== '') {
            $pattern = '%' . strtr($criteria->search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where .= " AND (h.description LIKE ? ESCAPE '!' OR h.hostname LIKE ? ESCAPE '!')";
            $parameters = [$pattern, $pattern];
        }
        if ($criteria->state === 'disabled') {
            $where .= " AND h.disabled = 'on'";
        } elseif ($criteria->state === 'enabled') {
            $where .= " AND (h.disabled = '' OR h.disabled IS NULL)";
        }
        $where .= ' AND (' . $this->visibility->predicate($userId) . ')';
        $query = $db->prepare("SELECT DISTINCT h.id, h.description, h.hostname, h.disabled, h.status
            FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id
            WHERE $where ORDER BY h.description ASC, h.id ASC LIMIT " . $criteria->offset() . ',' . ($criteria->pageSize + 1));
        $query->execute($parameters);
        $rows = $query->fetchAll();
        $hasNext = count($rows) > $criteria->pageSize;
        $devices = [];
        foreach (array_slice($rows, 0, $criteria->pageSize) as $row) {
            $devices[] = new DeviceSummary(
                (int) $row['id'],
                $row['description'],
                (string) $row['hostname'],
                $row['disabled'] === 'on',
                match ((int) $row['status']) {
                    1 => 'Down', 2 => 'Recovering', 3 => 'Up', default => 'Unknown'
                }
            );
        }

        return new DevicePage($devices, $hasNext);
    }
}
