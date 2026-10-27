<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\DataInput\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Navigation\Contract\ManagementCatalog;
use Kadupul\Navigation\Contract\Management\CatalogFilters;
use Kadupul\Navigation\Contract\Management\CatalogPage;
use Kadupul\Navigation\Contract\Management\CatalogParentNotFound;
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AutoconfigureTag('kadupul.management_catalog')]
final readonly class DbalQueryGraphCatalog implements ManagementCatalog
{
    public function __construct(#[Autowire(service: 'doctrine.dbal.web_connection')] private Connection $database) {}

    public function supports(CatalogScreen $screen): bool
    {
        return $screen === CatalogScreen::QueryGraphs;
    }

    public function read(CatalogScreen $screen, CatalogFilters $filters, int $actorId, int $parentId): CatalogPage
    {
        if (!$this->supports($screen) || !array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Unsupported query graph catalog.');
        }
        $parent = $this->database->fetchOne('SELECT name FROM snmp_query WHERE id = ?', [$parentId]);
        if ($parent === false) {
            throw new CatalogParentNotFound();
        }
        $records = $this->database->fetchAllAssociative('SELECT qg.id, qg.name, gt.name AS template, COALESCE(d.data_sources, 0) AS data_sources
            FROM snmp_query_graph qg LEFT JOIN graph_templates gt ON gt.id = qg.graph_template_id
            LEFT JOIN (SELECT snmp_query_graph_id, COUNT(*) AS data_sources FROM snmp_query_graph_rrd GROUP BY snmp_query_graph_id) d ON d.snmp_query_graph_id = qg.id
            WHERE qg.snmp_query_id = ? AND qg.name LIKE ? ORDER BY `' . $filters->sortColumn . '` ' . $filters->sortDirection . ', qg.id ASC LIMIT '
            . ($filters->rows + 1) . ' OFFSET ' . (($filters->page - 1) * $filters->rows), [$parentId, '%' . $filters->filter . '%']);
        return new CatalogPage($records, $parent);
    }
}
