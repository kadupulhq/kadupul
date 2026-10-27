<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
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
final readonly class DbalMappingCatalog implements ManagementCatalog
{
    public function __construct(#[Autowire(service: 'doctrine.dbal.web_connection')] private Connection $database) {}

    public function supports(CatalogScreen $screen): bool
    {
        return in_array($screen, [CatalogScreen::InputItems, CatalogScreen::QuerySources, CatalogScreen::QueryGraphSuggestions,
            CatalogScreen::QueryDataSuggestions, CatalogScreen::QueryDeviceTemplates, CatalogScreen::GraphDeviceTemplates], true);
    }

    public function read(CatalogScreen $screen, CatalogFilters $filters, int $actorId, int $parentId): CatalogPage
    {
        if (!$this->supports($screen) || !array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Unsupported mapping catalog.');
        }
        $parentSql = match ($screen) {
            CatalogScreen::InputItems => 'SELECT name, graph_template_id AS owner_id FROM graph_template_input WHERE id = ?',
            CatalogScreen::QuerySources, CatalogScreen::QueryGraphSuggestions, CatalogScreen::QueryDataSuggestions => 'SELECT name, snmp_query_id AS owner_id FROM snmp_query_graph WHERE id = ?',
            CatalogScreen::QueryDeviceTemplates => 'SELECT name FROM snmp_query WHERE id = ?',
            CatalogScreen::GraphDeviceTemplates => 'SELECT name FROM graph_templates WHERE id = ?',
            default => throw new \LogicException('Missing mapping parent.'),
        };
        $parent = $this->database->fetchAssociative($parentSql, [$parentId]);
        if ($parent === false) {
            throw new CatalogParentNotFound();
        }
        $source = match ($screen) {
            CatalogScreen::InputItems => 'SELECT link.graph_template_item_id AS id, item.text_format AS name, item.sequence,
                item.graph_type_id AS type, item.consolidation_function_id AS consolidation, CASE WHEN item.id IS NULL THEN 0 ELSE 1 END AS resolved
                FROM graph_template_input_defs link INNER JOIN graph_template_input owner ON owner.id = link.graph_template_input_id
                LEFT JOIN graph_templates_item item ON item.id = link.graph_template_item_id AND item.graph_template_id = owner.graph_template_id AND item.local_graph_id = 0
                WHERE link.graph_template_input_id = ?',
            CatalogScreen::QuerySources => 'SELECT link.data_template_rrd_id AS id, link.snmp_field_name AS name, link.data_template_id, template.name AS template,
                source.data_source_name AS data_source, CASE WHEN source.id IS NULL THEN 0 ELSE 1 END AS resolved
                FROM snmp_query_graph_rrd link LEFT JOIN data_template template ON template.id = link.data_template_id
                LEFT JOIN data_template_rrd source ON source.id = link.data_template_rrd_id AND source.data_template_id = link.data_template_id AND source.local_data_id = 0
                WHERE link.snmp_query_graph_id = ?',
            CatalogScreen::QueryGraphSuggestions => 'SELECT id, field_name AS name, sequence, text AS value FROM snmp_query_graph_sv WHERE snmp_query_graph_id = ?',
            CatalogScreen::QueryDataSuggestions => 'SELECT suggestion.id, suggestion.field_name AS name, suggestion.sequence, suggestion.data_template_id, template.name AS template, suggestion.text AS value
                FROM snmp_query_graph_rrd_sv suggestion LEFT JOIN data_template template ON template.id = suggestion.data_template_id WHERE suggestion.snmp_query_graph_id = ?',
            CatalogScreen::QueryDeviceTemplates => 'SELECT link.host_template_id AS id, template.name FROM host_template_snmp_query link
                LEFT JOIN host_template template ON template.id = link.host_template_id WHERE link.snmp_query_id = ?',
            CatalogScreen::GraphDeviceTemplates => 'SELECT link.host_template_id AS id, template.name FROM host_template_graph link
                LEFT JOIN host_template template ON template.id = link.host_template_id WHERE link.graph_template_id = ?',
            default => throw new \LogicException('Missing mapping query.'),
        };
        $tie = $screen === CatalogScreen::QuerySources ? ', listing.data_template_id ASC, listing.id ASC' : ', listing.id ASC';
        $records = $this->database->fetchAllAssociative('SELECT listing.* FROM (' . $source . ') listing WHERE COALESCE(listing.name, \'\') LIKE ? ORDER BY listing.`'
            . $filters->sortColumn . '` ' . $filters->sortDirection . $tie . ' LIMIT ' . ($filters->rows + 1) . ' OFFSET ' . (($filters->page - 1) * $filters->rows), [$parentId, '%' . $filters->filter . '%']);
        $operation = $screen->legacyQuery($parentId);
        if ($screen === CatalogScreen::InputItems) {
            $operation['graph_template_id'] = (int) $parent['owner_id'];
        }
        if (in_array($screen, [CatalogScreen::QuerySources, CatalogScreen::QueryGraphSuggestions, CatalogScreen::QueryDataSuggestions], true)) {
            $operation['snmp_query_id'] = (int) $parent['owner_id'];
        }
        return new CatalogPage($records, $parent['name'], $operation);
    }
}
