<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Collection\Application\Port\AutomationGraphRuleCatalog;
use Kadupul\Collection\Application\ReadModel\AutomationGraphRulePage;
use Kadupul\Collection\Application\ReadModel\AutomationGraphRuleSummary;
use Kadupul\Collection\Domain\AutomationGraphRuleCriteria;

final readonly class DoctrineAutomationGraphRuleCatalog implements AutomationGraphRuleCatalog
{
    public function __construct(private Connection $database) {}

    public function list(AutomationGraphRuleCriteria $criteria): AutomationGraphRulePage
    {
        $conditions = [];
        $parameters = [];
        if ($criteria->search !== '') {
            $conditions[] = '(agr.name LIKE ? OR sqg.name LIKE ? OR sq.name LIKE ?)';
            $needle = '%' . $criteria->search . '%';
            array_push($parameters, $needle, $needle, $needle);
        }
        if ($criteria->status === 'enabled') {
            $conditions[] = "agr.enabled = 'on'";
        } elseif ($criteria->status === 'disabled') {
            $conditions[] = "agr.enabled = ''";
        }
        if ($criteria->dataQueryId >= 0) {
            $conditions[] = 'agr.snmp_query_id = ?';
            $parameters[] = $criteria->dataQueryId;
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $sort = [
            'name' => 'agr.name', 'data_query' => 'sq.name', 'graph_type' => 'sqg.name',
            'enabled' => 'agr.enabled', 'id' => 'agr.id',
        ][$criteria->sort];
        $direction = strtoupper($criteria->direction);
        $rows = $this->database->fetchAllAssociative("SELECT agr.id, agr.name, agr.enabled,
                sq.name AS data_query_name, sqg.name AS graph_type_name
            FROM automation_graph_rules agr
            LEFT JOIN snmp_query sq ON sq.id = agr.snmp_query_id
            LEFT JOIN snmp_query_graph sqg ON sqg.id = agr.graph_type_id
            $where
            ORDER BY $sort $direction, agr.id $direction
            LIMIT " . $criteria->offset() . ',' . ($criteria->pageSize + 1), $parameters);

        $rules = [];
        foreach (array_slice($rows, 0, $criteria->pageSize) as $row) {
            $rules[] = new AutomationGraphRuleSummary(
                (int) $row['id'],
                (string) $row['name'],
                (string) ($row['data_query_name'] ?? ''),
                (string) ($row['graph_type_name'] ?? ''),
                (string) ($row['enabled'] ?? '') === 'on'
            );
        }

        return new AutomationGraphRulePage($rules, count($rows) > $criteria->pageSize);
    }
}
