<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Navigation\Contract\RelationshipCatalog;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Relationships\RelationshipPage;
use Kadupul\Navigation\Contract\Relationships\RelationshipParentNotFound;
use Kadupul\Navigation\Contract\Relationships\RelationshipScreen;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AutoconfigureTag('kadupul.relationship_catalog')]
final readonly class DbalTemplateRelationships implements RelationshipCatalog
{
    public function __construct(#[Autowire(service: 'doctrine.dbal.web_connection')] private Connection $database) {}

    public function supports(RelationshipScreen $screen): bool
    {
        return in_array($screen, [RelationshipScreen::DeviceQueries, RelationshipScreen::DataDefinitions, RelationshipScreen::AggregateItems], true);
    }

    public function read(RelationshipScreen $screen, ListFilters $filters, int $parentId): RelationshipPage
    {
        $table = match ($screen) {
            RelationshipScreen::DeviceQueries => 'host_template', RelationshipScreen::DataDefinitions => 'data_template',
            RelationshipScreen::AggregateItems => 'aggregate_graph_templates', default => throw new \InvalidArgumentException('Unsupported template relationships.'),
        };
        $parent = $this->database->fetchOne("SELECT name FROM $table WHERE id = ?", [$parentId]);
        if ($parent === false) {
            throw new RelationshipParentNotFound();
        }
        $source = match ($screen) {
            RelationshipScreen::DeviceQueries => 'SELECT q.id, q.name, di.name AS input_method, COALESCE(g.graphs, 0) AS graphs
                FROM host_template_snmp_query link INNER JOIN snmp_query q ON q.id = link.snmp_query_id
                LEFT JOIN data_input di ON di.id = q.data_input_id
                LEFT JOIN (SELECT snmp_query_id, COUNT(*) AS graphs FROM snmp_query_graph GROUP BY snmp_query_id) g ON g.snmp_query_id = q.id
                WHERE link.host_template_id = ?',
            RelationshipScreen::DataDefinitions => 'SELECT d.id, d.data_source_name AS name, d.data_source_type_id AS source_type,
                d.rrd_heartbeat AS heartbeat, d.rrd_minimum AS minimum, d.rrd_maximum AS maximum
                FROM data_template_rrd d WHERE d.data_template_id = ? AND d.local_data_id = 0',
            RelationshipScreen::AggregateItems => "SELECT a.graph_templates_item_id AS id, item.text_format AS name, a.sequence,
                CASE WHEN item.id IS NULL THEN 0 ELSE 1 END AS resolved, a.item_skip AS skip, a.item_total AS total,
                CASE WHEN a.t_graph_type_id = 'on' THEN a.graph_type_id ELSE item.graph_type_id END AS graph_type,
                c.name AS cdef, ct.name AS color_template
                FROM aggregate_graph_templates_item a INNER JOIN aggregate_graph_templates p ON p.id = a.aggregate_template_id
                LEFT JOIN graph_templates_item item ON item.id = a.graph_templates_item_id AND item.graph_template_id = p.graph_template_id AND item.local_graph_id = 0
                LEFT JOIN cdef c ON c.id = CASE WHEN a.t_cdef_id = 'on' THEN a.cdef_id ELSE item.cdef_id END
                LEFT JOIN color_templates ct ON ct.color_template_id = a.color_template
                WHERE a.aggregate_template_id = ?",
            default => throw new \LogicException('Missing template relationship query.'),
        };
        if (!array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Invalid relationship sort.');
        }
        $records = $this->database->fetchAllAssociative('SELECT listing.* FROM (' . $source . ') listing WHERE COALESCE(listing.name, \'\') LIKE ? ORDER BY listing.`'
            . $filters->sortColumn . '` ' . $filters->sortDirection . ', listing.id ASC LIMIT ' . ($filters->rows + 1) . ' OFFSET ' . (($filters->page - 1) * $filters->rows), [$parentId, '%' . $filters->filter . '%']);
        return new RelationshipPage($parent, $records);
    }
}
