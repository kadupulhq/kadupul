<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Kadupul\Navigation\Contract\AdministrationCatalog;
use Kadupul\Navigation\Contract\Administration\ListFilters;
use Kadupul\Navigation\Contract\Administration\ListScreen;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('kadupul.administration_catalog')]
final readonly class DbalAutomationAdministrationCatalog implements AdministrationCatalog
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private Connection $database,
    ) {}

    public function supports(ListScreen $screen): bool
    {
        return in_array($screen, [ListScreen::SnmpOptions, ListScreen::GraphRules, ListScreen::TreeRules, ListScreen::Networks], true);
    }

    public function read(ListScreen $screen, ListFilters $filters): array
    {
        $source = match ($screen) {
            ListScreen::SnmpOptions => <<<'SQL'
SELECT s.id, s.name, COALESCE(n.networks, 0) AS networks, COALESCE(i.totals, 0) AS totals,
 COALESCE(i.v1entries, 0) AS v1entries, COALESCE(i.v2entries, 0) AS v2entries, COALESCE(i.v3entries, 0) AS v3entries
 FROM automation_snmp s
 LEFT JOIN (SELECT snmp_id, COUNT(*) AS networks FROM automation_networks GROUP BY snmp_id) n ON n.snmp_id = s.id
 LEFT JOIN (SELECT snmp_id, COUNT(*) AS totals,
 SUM(CASE WHEN snmp_version = 1 THEN 1 ELSE 0 END) AS v1entries,
 SUM(CASE WHEN snmp_version = 2 THEN 1 ELSE 0 END) AS v2entries,
 SUM(CASE WHEN snmp_version = 3 THEN 1 ELSE 0 END) AS v3entries FROM automation_snmp_items GROUP BY snmp_id) i ON i.snmp_id = s.id
SQL,
            ListScreen::GraphRules => <<<'SQL'
SELECT r.id, r.name, r.enabled, sq.name AS snmp_query_name, sqg.name AS graph_type_name
 FROM automation_graph_rules r
 LEFT JOIN snmp_query sq ON sq.id = r.snmp_query_id
 LEFT JOIN snmp_query_graph sqg ON sqg.id = r.graph_type_id
SQL,
            ListScreen::TreeRules => <<<'SQL'
SELECT r.id, r.name, r.enabled, r.leaf_type, r.host_grouping_type, gt.name AS tree_name, gti.title AS subtree_name
 FROM automation_tree_rules r
 LEFT JOIN graph_tree gt ON gt.id = r.tree_id
 LEFT JOIN graph_tree_items gti ON gti.id = r.tree_item_id
SQL,
            ListScreen::Networks => <<<'SQL'
SELECT n.id, n.name, p.name AS data_collector, n.sched_type, n.total_ips,
 CASE WHEN COALESCE(n.enabled, '') = '' THEN 'Disabled' WHEN COALESCE(pr.running_processes, 0) > 0 THEN 'Running' ELSE 'Idle' END AS network_status,
 CASE WHEN COALESCE(n.enabled, '') <> '' AND COALESCE(pr.running_processes, 0) > 0 THEN COALESCE(i.pending, 0) ELSE 0 END AS pending,
 CASE WHEN COALESCE(n.enabled, '') <> '' AND COALESCE(pr.running_processes, 0) > 0 THEN COALESCE(i.running, 0) ELSE 0 END AS running,
 CASE WHEN COALESCE(n.enabled, '') <> '' AND COALESCE(pr.running_processes, 0) > 0 THEN COALESCE(i.done, 0) ELSE 0 END AS done,
 CASE WHEN COALESCE(n.enabled, '') = '' THEN 0 WHEN COALESCE(pr.running_processes, 0) > 0 THEN COALESCE(pr.up_hosts, 0) ELSE n.up_hosts END AS up_hosts,
 CASE WHEN COALESCE(n.enabled, '') = '' THEN 0 WHEN COALESCE(pr.running_processes, 0) > 0 THEN COALESCE(pr.snmp_hosts, 0) ELSE n.snmp_hosts END AS snmp_hosts,
 n.threads, n.last_runtime,
 CASE WHEN COALESCE(n.enabled, '') = '' OR n.sched_type = 1 THEN NULL
 WHEN n.next_start = '0000-00-00 00:00:00' THEN n.start_at ELSE n.next_start END AS next_start, n.last_started
 FROM automation_networks n
 LEFT JOIN poller p ON p.id = n.poller_id
 LEFT JOIN (SELECT network_id, SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS pending,
 SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS running, SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS done
 FROM automation_ips GROUP BY network_id) i ON i.network_id = n.id
 LEFT JOIN (SELECT network_id, SUM(CASE WHEN status <> 'done' THEN 1 ELSE 0 END) AS running_processes,
 SUM(up_hosts) AS up_hosts, SUM(snmp_hosts) AS snmp_hosts FROM automation_processes GROUP BY network_id) pr ON pr.network_id = n.id
SQL,
            default => throw new \InvalidArgumentException('Unsupported administration list.'),
        };
        // Only selected public fields leave this adapter. Sorting uses aliases
        // from the fixed screen definition, never request text as an identifier.
        if (!array_key_exists($filters->sortColumn, $screen->columns())) {
            throw new \InvalidArgumentException('Invalid list sort.');
        }
        $search = match ($screen) {
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
