<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Navigation\Contract\ManagementCatalog;
use Kadupul\Navigation\Contract\Management\CatalogFilters;
use Kadupul\Navigation\Contract\Management\CatalogPage;
use Kadupul\Navigation\Contract\Management\CatalogParentNotFound;
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AutoconfigureTag('kadupul.management_catalog')]
final readonly class DbalGraphingManagementCatalog implements ManagementCatalog
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        private ManagementGraphScope $visibility,
    ) {}

    public function supports(CatalogScreen $screen): bool
    {
        return in_array($screen, [CatalogScreen::Graphs, CatalogScreen::DataSources, CatalogScreen::Aggregates, CatalogScreen::GraphItems, CatalogScreen::GraphInputs, CatalogScreen::DeviceGraphs], true);
    }

    public function read(CatalogScreen $screen, CatalogFilters $filters, int $actorId, int $parentId): CatalogPage
    {
        $parameters = [];
        $parentName = null;
        $scope = in_array($screen, [CatalogScreen::Graphs, CatalogScreen::DataSources, CatalogScreen::Aggregates], true)
            ? $this->visibility->forActor($actorId) : [];
        if ($screen->scoped()) {
            $table = $screen === CatalogScreen::DeviceGraphs ? 'host_template' : 'graph_templates';
            $parentName = $this->database->fetchOne("SELECT name FROM $table WHERE id = ?", [$parentId]);
            if ($parentName === false) {
                throw new CatalogParentNotFound();
            }
            $parameters[] = $parentId;
        }
        $source = match ($screen) {
            CatalogScreen::Graphs => "SELECT gl.id, gtg.title_cache AS name, h.description AS device, gt.name AS template,
                CONCAT(gtg.height, 'x', gtg.width) AS size, gl.snmp_query_id AS query_id, gl.snmp_index
                FROM graph_local gl INNER JOIN graph_templates_graph gtg ON gtg.local_graph_id = gl.id
                LEFT JOIN host h ON h.id = gl.host_id LEFT JOIN graph_templates gt ON gt.id = gl.graph_template_id
                WHERE gl.id IN ({$scope['graphs']}) AND (gl.host_id = 0 OR gl.host_id IN ({$scope['devices']}))
                AND NOT EXISTS (SELECT 1 FROM aggregate_graphs ag WHERE ag.local_graph_id = gl.id)",
            CatalogScreen::DataSources => "SELECT dl.id, dtd.name_cache AS name, h.description AS device, dt.name AS template,
                dtd.active, dtd.rrd_step, dsp.name AS profile FROM data_local dl
                INNER JOIN data_template_data dtd ON dtd.local_data_id = dl.id
                LEFT JOIN data_template dt ON dt.id = dl.data_template_id LEFT JOIN host h ON h.id = dl.host_id
                LEFT JOIN data_source_profiles dsp ON dsp.id = dtd.data_source_profile_id
                WHERE dl.host_id = 0 OR dl.host_id IN ({$scope['devices']})",
            CatalogScreen::Aggregates => "SELECT ag.id, gtg.title_cache AS name, agt.name AS template, ag.local_graph_id AS graph_id,
                CONCAT(gtg.height, 'x', gtg.width) AS size, COALESCE(m.members, 0) AS members
                FROM aggregate_graphs ag INNER JOIN graph_local gl ON gl.id = ag.local_graph_id
                INNER JOIN graph_templates_graph gtg ON gtg.local_graph_id = gl.id
                LEFT JOIN aggregate_graph_templates agt ON agt.id = ag.aggregate_template_id
                LEFT JOIN (SELECT aggregate_graph_id, COUNT(*) AS members FROM aggregate_graphs_items GROUP BY aggregate_graph_id) m ON m.aggregate_graph_id = ag.id
                WHERE gl.host_id = 0 AND gl.graph_template_id = 0 AND gl.id IN ({$scope['graphs']})",
            CatalogScreen::GraphItems => "SELECT gti.id, gti.text_format AS name, gti.sequence, gti.graph_type_id AS type,
                dtr.data_source_name AS data_source, c.name AS cdef, v.name AS vdef, gti.value
                FROM graph_templates_item gti LEFT JOIN data_template_rrd dtr ON dtr.id = gti.task_item_id
                LEFT JOIN cdef c ON c.id = gti.cdef_id LEFT JOIN vdef v ON v.id = gti.vdef_id
                WHERE gti.graph_template_id = ? AND gti.local_graph_id = 0",
            CatalogScreen::GraphInputs => "SELECT gti.id, gti.name, gti.description, gti.column_name AS field, COALESCE(d.items, 0) AS items
                FROM graph_template_input gti LEFT JOIN (
                    SELECT defs.graph_template_input_id, COUNT(*) AS items FROM graph_template_input_defs defs
                    INNER JOIN graph_templates_item item ON item.id = defs.graph_template_item_id
                    INNER JOIN graph_template_input owner ON owner.id = defs.graph_template_input_id
                    WHERE item.graph_template_id = owner.graph_template_id AND item.local_graph_id = 0
                    GROUP BY defs.graph_template_input_id) d ON d.graph_template_input_id = gti.id
                WHERE gti.graph_template_id = ?",
            CatalogScreen::DeviceGraphs => "SELECT gt.id, gt.name, CONCAT(gtg.height, 'x', gtg.width) AS size, gtg.image_format_id AS image_format, gtg.vertical_label
                FROM host_template_graph link INNER JOIN graph_templates gt ON gt.id = link.graph_template_id
                LEFT JOIN graph_templates_graph gtg ON gtg.graph_template_id = gt.id AND gtg.local_graph_id = 0
                WHERE link.host_template_id = ?",
            default => throw new \InvalidArgumentException('Unsupported graphing catalog.'),
        };
        if (!array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Invalid catalog sort.');
        }
        $parameters[] = '%' . $filters->filter . '%';
        $records = $this->database->fetchAllAssociative('SELECT listing.* FROM (' . $source . ') listing WHERE listing.name LIKE ? ORDER BY listing.`'
            . $filters->sortColumn . '` ' . $filters->sortDirection . ', listing.id ASC LIMIT ' . ($filters->rows + 1) . ' OFFSET ' . (($filters->page - 1) * $filters->rows), $parameters);
        return new CatalogPage($records, $parentName);
    }
}
