<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\SiteCatalog;
use Kadupul\Inventory\Application\ReadModel\SitePage;
use Kadupul\Inventory\Application\ReadModel\SiteSummary;
use Kadupul\Inventory\Domain\SiteListCriteria;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacySiteCatalog implements SiteCatalog
{
    public function __construct(private DatabaseConnection $database, private LegacyDeviceVisibility $visibility) {}

    public function listFor(int $userId, SiteListCriteria $criteria): SitePage
    {
        $predicate = $this->visibility->predicate($userId);
        $where = 's.id > 0';
        $parameters = [];
        if ($criteria->search !== '') {
            $pattern = '%' . strtr($criteria->search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where .= " AND (s.name LIKE ? ESCAPE '!' OR s.city LIKE ? ESCAPE '!' OR s.state LIKE ? ESCAPE '!' OR s.country LIKE ? ESCAPE '!')";
            $parameters = [$pattern, $pattern, $pattern, $pattern];
        }
        $direction = $criteria->direction === 'desc' ? 'DESC' : 'ASC';
        $query = $this->database->get()->prepare("SELECT s.id, s.name, s.city, s.state, s.country, COALESCE(d.devices, 0) AS devices
            FROM sites s LEFT JOIN (
                SELECT h.site_id, COUNT(DISTINCT h.id) AS devices
                FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id
                WHERE h.id > 0 AND h.deleted = '' AND ($predicate)
                GROUP BY h.site_id
            ) d ON d.site_id = s.id
            WHERE $where ORDER BY s.name $direction, s.id $direction LIMIT " . $criteria->offset() . ',' . ($criteria->pageSize + 1));
        $query->execute($parameters);
        $rows = $query->fetchAll();
        $sites = [];
        foreach (array_slice($rows, 0, $criteria->pageSize) as $row) {
            $sites[] = new SiteSummary((int) $row['id'], $row['name'], $row['city'] ?? '', $row['state'] ?? '', $row['country'] ?? '', (int) $row['devices']);
        }
        return new SitePage($sites, count($rows) > $criteria->pageSize);
    }
}
