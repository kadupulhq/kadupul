<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\IdentityAccess\Contract\ManagementVisibility;
use Kadupul\Navigation\Contract\ManagementCatalog;
use Kadupul\Navigation\Contract\Management\CatalogFilters;
use Kadupul\Navigation\Contract\Management\CatalogPage;
use Kadupul\Navigation\Contract\Management\CatalogParentNotFound;
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AutoconfigureTag('kadupul.management_catalog')]
final readonly class DbalInstanceCatalog implements ManagementCatalog
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
        private ManagementVisibility $visibility,
    ) {}

    public function supports(CatalogScreen $screen): bool
    {
        return in_array($screen, [CatalogScreen::LocalGraphItems, CatalogScreen::GraphSources,
            CatalogScreen::AggregateMembers, CatalogScreen::SourceDefinitions, CatalogScreen::SourceGraphs, CatalogScreen::TreeItems], true);
    }

    public function read(CatalogScreen $screen, CatalogFilters $filters, int $actorId, int $parentId): CatalogPage
    {
        if (!$this->supports($screen) || $actorId < 1 || !array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Unsupported instance catalog.');
        }
        if ($screen === CatalogScreen::TreeItems) {
            return $this->tree($filters, $parentId);
        }
        $scope = $this->visibility->forActor($actorId);
        $graphs = "gl.id IN ({$scope['graphs']}) AND (gl.host_id = 0 OR gl.host_id IN ({$scope['devices']}))";
        $sources = "(dl.host_id = 0 OR dl.host_id IN ({$scope['devices']}))";
        $parentSql = match ($screen) {
            CatalogScreen::SourceDefinitions, CatalogScreen::SourceGraphs => "SELECT dtd.name_cache FROM data_local dl
                INNER JOIN data_template_data dtd ON dtd.local_data_id = dl.id WHERE dl.id = ? AND $sources",
            CatalogScreen::AggregateMembers => "SELECT gtg.title_cache FROM aggregate_graphs ag
                INNER JOIN graph_local gl ON gl.id = ag.local_graph_id
                INNER JOIN graph_templates_graph gtg ON gtg.local_graph_id = gl.id WHERE ag.id = ? AND $graphs",
            default => "SELECT gtg.title_cache FROM graph_local gl
                INNER JOIN graph_templates_graph gtg ON gtg.local_graph_id = gl.id WHERE gl.id = ? AND $graphs",
        };
        $name = $this->database->fetchOne($parentSql, [$parentId]);
        if ($name === false) {
            throw new CatalogParentNotFound();
        }
        $source = match ($screen) {
            CatalogScreen::LocalGraphItems => 'SELECT item.id, item.text_format AS name, item.sequence,
                item.graph_type_id AS type, item.task_item_id AS source_id, item.value
                FROM graph_templates_item item WHERE item.local_graph_id = ?',
            CatalogScreen::GraphSources => "SELECT DISTINCT dl.id, dtd.name_cache AS name, h.description AS device, dtd.active, dtd.rrd_step
                FROM graph_templates_item item INNER JOIN data_template_rrd rrd ON rrd.id = item.task_item_id
                INNER JOIN data_local dl ON dl.id = rrd.local_data_id
                INNER JOIN data_template_data dtd ON dtd.local_data_id = dl.id
                LEFT JOIN host h ON h.id = dl.host_id WHERE item.local_graph_id = ? AND $sources",
            CatalogScreen::AggregateMembers => "SELECT gl.id, gtg.title_cache AS name, members.sequence, h.description AS device
                FROM aggregate_graphs_items members INNER JOIN graph_local gl ON gl.id = members.local_graph_id
                INNER JOIN graph_templates_graph gtg ON gtg.local_graph_id = gl.id
                LEFT JOIN host h ON h.id = gl.host_id WHERE members.aggregate_graph_id = ? AND $graphs",
            CatalogScreen::SourceDefinitions => 'SELECT id, data_source_name AS name, data_source_type_id AS source_type,
                rrd_heartbeat AS heartbeat, rrd_minimum AS minimum, rrd_maximum AS maximum
                FROM data_template_rrd WHERE local_data_id = ?',
            default => "SELECT DISTINCT gl.id, gtg.title_cache AS name, h.description AS device, gt.name AS template
                FROM data_template_rrd rrd INNER JOIN graph_templates_item item ON item.task_item_id = rrd.id
                INNER JOIN graph_local gl ON gl.id = item.local_graph_id
                INNER JOIN graph_templates_graph gtg ON gtg.local_graph_id = gl.id
                LEFT JOIN graph_templates gt ON gt.id = gl.graph_template_id LEFT JOIN host h ON h.id = gl.host_id
                WHERE rrd.local_data_id = ? AND $graphs",
        };
        $rows = $this->database->fetchAllAssociative(
            'SELECT listing.* FROM (' . $source . ') listing
            WHERE COALESCE(listing.name, \'\') LIKE ? ORDER BY listing.`' . $filters->sortColumn . '` ' . $filters->sortDirection
            . ', listing.id ASC LIMIT ' . $filters->fetchLimit() . ' OFFSET ' . $filters->offset(),
            [$parentId, '%' . $filters->filter . '%']
        );
        return CatalogPage::fromRows($rows, $name);
    }

    private function tree(CatalogFilters $filters, int $parentId): CatalogPage
    {
        $name = $this->database->fetchOne('SELECT name FROM graph_tree WHERE id = ?', [$parentId]);
        if ($name === false) {
            throw new CatalogParentNotFound();
        }
        // Tree administration displays stored placements, without resolving protected names.
        $records = $this->database->fetchAllAssociative(
            'SELECT id, title AS name, parent, position,
            local_graph_id AS graph_id, host_id AS device_id, site_id FROM graph_tree_items
            WHERE graph_tree_id = ? AND COALESCE(title, \'\') LIKE ? ORDER BY `' . $filters->sortColumn . '` '
            . $filters->sortDirection . ', id ASC LIMIT ' . $filters->fetchLimit() . ' OFFSET ' . $filters->offset(),
            [$parentId, '%' . $filters->filter . '%']
        );
        return CatalogPage::fromRows($records, $name);
    }
}
