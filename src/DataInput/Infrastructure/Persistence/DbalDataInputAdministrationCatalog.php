<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\DataInput\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Kadupul\Navigation\Contract\AdministrationCatalog;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Administration\ListScreen;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('kadupul.administration_catalog')]
final readonly class DbalDataInputAdministrationCatalog implements AdministrationCatalog
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
    ) {}

    public function supports(ListScreen $screen): bool
    {
        return in_array($screen, [ListScreen::DataQueries], true);
    }

    public function read(ListScreen $screen, ListFilters $filters): array
    {
        $source = match ($screen) {
            ListScreen::DataQueries => <<<'SQL'
SELECT sq.id, sq.name, di.name AS data_input_method,
 COALESCE(g.graphs, 0) AS graphs, COALESCE(t.templates, 0) AS templates
 FROM snmp_query sq
 LEFT JOIN data_input di ON di.id = sq.data_input_id
 LEFT JOIN (SELECT snmp_query_id, COUNT(*) AS graphs FROM graph_local GROUP BY snmp_query_id) g ON g.snmp_query_id = sq.id
 LEFT JOIN (SELECT snmp_query_id, COUNT(DISTINCT graph_template_id) AS templates FROM snmp_query_graph GROUP BY snmp_query_id) t ON t.snmp_query_id = sq.id
SQL,
            default => throw new \InvalidArgumentException('Unsupported administration list.'),
        };
        // Only selected public fields leave this adapter. Sorting uses aliases
        // from the fixed screen definition, never request text as an identifier.
        if (!array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Invalid list sort.');
        }
        $search = match ($screen) {
            ListScreen::DataQueries => ['name', 'data_input_method'],
            default => ['name'],
        };
        $where = implode(' OR ', array_map(static fn(string $column): string => 'listing.' . $column . ' LIKE ?', $search));
        $params = array_fill(0, count($search), '%' . $filters->filter . '%');
        $types = array_fill(0, count($search), ParameterType::STRING);
        return $this->database->fetchAllAssociative(
            'SELECT listing.* FROM (' . $source . ') listing WHERE (' . $where . ') ORDER BY listing.'
                . $filters->sortColumn . ' ' . $filters->sortDirection . ', listing.id ASC LIMIT ? OFFSET ?',
            [...$params, $filters->rows + 1, ($filters->page - 1) * $filters->rows],
            [...$types, ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }
}
