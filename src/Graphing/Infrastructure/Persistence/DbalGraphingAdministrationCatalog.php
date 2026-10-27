<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Kadupul\Navigation\Contract\AdministrationCatalog;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Administration\ListScreen;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('kadupul.administration_catalog')]
final readonly class DbalGraphingAdministrationCatalog implements AdministrationCatalog
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
    ) {}

    public function supports(ListScreen $screen): bool
    {
        return in_array($screen, [ListScreen::DeviceTemplates, ListScreen::GraphTemplates, ListScreen::DataTemplates, ListScreen::AggregateTemplates, ListScreen::Trees], true);
    }

    public function read(ListScreen $screen, ListFilters $filters): array
    {
        $source = match ($screen) {
            ListScreen::Trees => <<<'SQL'
SELECT t.id, t.name, t.enabled, t.locked, owner.username AS owner, t.sequence,
 t.last_modified, editor.username AS modified_by,
 COALESCE(i.sites, 0) AS sites, COALESCE(i.branches, 0) AS branches, COALESCE(i.hosts, 0) AS hosts, COALESCE(i.graphs, 0) AS graphs
 FROM graph_tree t
 LEFT JOIN user_auth owner ON owner.id = t.user_id
 LEFT JOIN user_auth editor ON editor.id = t.modified_by
 LEFT JOIN (SELECT graph_tree_id,
 SUM(CASE WHEN site_id > 0 THEN 1 ELSE 0 END) AS sites,
 SUM(CASE WHEN local_graph_id = 0 AND host_id = 0 AND site_id = 0 THEN 1 ELSE 0 END) AS branches,
 SUM(CASE WHEN host_id > 0 THEN 1 ELSE 0 END) AS hosts,
 SUM(CASE WHEN local_graph_id > 0 THEN 1 ELSE 0 END) AS graphs
 FROM graph_tree_items GROUP BY graph_tree_id) i ON i.graph_tree_id = t.id
SQL,

            ListScreen::DeviceTemplates => <<<'SQL'
SELECT ht.id, ht.name, ht.class AS device_class, COALESCE(h.hosts, 0) AS hosts
 FROM host_template ht
 LEFT JOIN (SELECT host_template_id, COUNT(*) AS hosts FROM host GROUP BY host_template_id) h ON h.host_template_id = ht.id
SQL,
            ListScreen::GraphTemplates => <<<'SQL'
SELECT gt.id, gt.name, COALESCE(g.graphs, 0) AS graphs,
 CONCAT(gtg.height, 'x', gtg.width) AS size, gtg.image_format_id AS image_format, gtg.vertical_label
 FROM graph_templates gt
 INNER JOIN graph_templates_graph gtg ON gtg.graph_template_id = gt.id AND gtg.local_graph_id = 0
 LEFT JOIN (SELECT graph_template_id, COUNT(*) AS graphs FROM graph_local GROUP BY graph_template_id) g ON g.graph_template_id = gt.id
SQL,
            ListScreen::DataTemplates => <<<'SQL'
SELECT dt.id, dt.name, di.name AS data_input_method, dtd.active, dsp.name AS profile_name,
 COALESCE(d.data_sources, 0) AS data_sources
 FROM data_template dt
 INNER JOIN data_template_data dtd ON dtd.data_template_id = dt.id AND dtd.local_data_id = 0
 LEFT JOIN data_source_profiles dsp ON dsp.id = dtd.data_source_profile_id
 LEFT JOIN data_input di ON di.id = dtd.data_input_id
 LEFT JOIN (SELECT data_template_id, COUNT(*) AS data_sources FROM data_template_data WHERE local_data_id > 0 GROUP BY data_template_id) d ON d.data_template_id = dt.id
SQL,
            ListScreen::AggregateTemplates => <<<'SQL'
SELECT agt.id, agt.name, gt.name AS graph_template_name, COALESCE(g.graphs, 0) AS graphs
 FROM aggregate_graph_templates agt
 LEFT JOIN graph_templates gt ON gt.id = agt.graph_template_id
 LEFT JOIN (SELECT aggregate_template_id, COUNT(*) AS graphs FROM aggregate_graphs GROUP BY aggregate_template_id) g ON g.aggregate_template_id = agt.id
SQL,
            default => throw new \InvalidArgumentException('Unsupported administration list.'),
        };
        // Only selected public fields leave this adapter. Sorting uses aliases
        // from the fixed screen definition, never request text as an identifier.
        if (!array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Invalid list sort.');
        }
        $search = match ($screen) {
            ListScreen::AggregateTemplates => ['name', 'graph_template_name'],
            default => ['name'],
        };
        $where = implode(' OR ', array_map(static fn(string $column): string => 'listing.' . $column . ' LIKE ?', $search));
        $params = array_fill(0, count($search), '%' . $filters->filter . '%');
        $types = array_fill(0, count($search), ParameterType::STRING);
        if ($screen === ListScreen::Trees) {
            // Match a child title without filtering the rows used for usage counts.
            $where .= ' OR EXISTS (SELECT 1 FROM graph_tree_items ti WHERE ti.graph_tree_id = listing.id AND ti.title LIKE ?)';
            $params[] = '%' . $filters->filter . '%';
            $types[] = ParameterType::STRING;
        }
        return $this->database->fetchAllAssociative(
            'SELECT listing.* FROM (' . $source . ') listing WHERE (' . $where . ') ORDER BY listing.'
                . $filters->sortColumn . ' ' . $filters->sortDirection . ', listing.id ASC LIMIT ? OFFSET ?',
            [...$params, $filters->rows + 1, ($filters->page - 1) * $filters->rows],
            [...$types, ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }
}
