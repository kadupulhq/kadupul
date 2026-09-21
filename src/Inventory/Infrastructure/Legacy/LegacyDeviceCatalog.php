<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceCatalog;
use Kadupul\Inventory\Application\ReadModel\DevicePage;
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
            $where .= " AND (h.description LIKE ? ESCAPE '!' OR h.hostname LIKE ? ESCAPE '!' OR h.location LIKE ? ESCAPE '!' OR h.external_id LIKE ? ESCAPE '!')";
            $parameters = [$pattern, $pattern, $pattern, $pattern];
        }
        if ($criteria->state === 'disabled') {
            $where .= " AND h.disabled = 'on'";
        } elseif ($criteria->state === 'enabled') {
            $where .= " AND (h.disabled = '' OR h.disabled IS NULL)";
        }
        if ($criteria->status === 'disabled') {
            $where .= " AND h.disabled = 'on'";
        } elseif ($criteria->status !== 'all') {
            $where .= " AND (h.disabled = '' OR h.disabled IS NULL)";
            if ($criteria->status === 'unknown') {
                $where .= ' AND (h.status NOT IN (1, 2, 3, 4) OR h.status IS NULL)';
            } else {
                $where .= ' AND h.status = ?';
                $parameters[] = match ($criteria->status) {
                    'down' => 1, 'recovering' => 2, 'up' => 3, 'error' => 4,
                };
            }
        }
        if ($criteria->siteId !== null) {
            $where .= ' AND h.site_id = ?';
            $parameters[] = $criteria->siteId;
        }
        $where .= ' AND (' . $this->visibility->predicate($userId) . ')';
        $column = match ($criteria->order->field) {
            'name' => 'h.description', 'hostname' => 'h.hostname',
        };
        $direction = $criteria->order->direction === 'desc' ? 'DESC' : 'ASC';
        $query = $db->prepare("SELECT DISTINCT h.id, h.description, h.hostname, h.disabled, h.status, h.location, h.external_id
            FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id
            WHERE $where ORDER BY $column $direction, h.id $direction LIMIT " . $criteria->offset() . ',' . ($criteria->pageSize + 1));
        $query->execute($parameters);
        $rows = $query->fetchAll();
        $hasNext = count($rows) > $criteria->pageSize;
        $devices = [];
        foreach (array_slice($rows, 0, $criteria->pageSize) as $row) {
            $devices[] = LegacyDeviceProjection::summary($row);
        }

        return new DevicePage($devices, $hasNext);
    }
}
