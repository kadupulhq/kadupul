<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Collection\Application\Port\AutomationTreeRuleCatalog;
use Kadupul\Collection\Application\ReadModel\AutomationTreeRulePage;
use Kadupul\Collection\Application\ReadModel\AutomationTreeRuleSummary;
use Kadupul\Collection\Domain\AutomationTreeRuleCriteria;

final readonly class DoctrineAutomationTreeRuleCatalog implements AutomationTreeRuleCatalog
{
    public function __construct(private Connection $database) {}

    public function list(AutomationTreeRuleCriteria $criteria): AutomationTreeRulePage
    {
        $conditions = [];
        $parameters = [];
        if ($criteria->search !== '') {
            $conditions[] = 'atr.name LIKE ?';
            $parameters[] = '%' . $criteria->search . '%';
        }
        if ($criteria->status === 'enabled') {
            $conditions[] = "atr.enabled = 'on'";
        } elseif ($criteria->status === 'disabled') {
            $conditions[] = "atr.enabled = ''";
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $sort = [
            'name' => 'atr.name',
            'tree' => 'gt.name',
            'subtree' => 'gti.title',
            'leaf_type' => 'atr.leaf_type',
            'host_grouping_type' => 'atr.host_grouping_type',
            'enabled' => 'atr.enabled',
            'id' => 'atr.id',
        ][$criteria->sort];
        $direction = strtoupper($criteria->direction);
        $rows = $this->database->fetchAllAssociative("SELECT atr.id, atr.name, atr.tree_id, atr.tree_item_id,
                atr.leaf_type, atr.host_grouping_type, atr.enabled, gt.name AS tree_name, gti.title AS subtree_name
            FROM automation_tree_rules atr
            LEFT JOIN graph_tree gt ON gt.id = atr.tree_id
            LEFT JOIN graph_tree_items gti ON gti.id = atr.tree_item_id AND gti.graph_tree_id = atr.tree_id
            $where
            ORDER BY $sort $direction, atr.id $direction
            LIMIT " . $criteria->offset() . ',' . ($criteria->pageSize + 1), $parameters);

        $rules = [];
        foreach (array_slice($rows, 0, $criteria->pageSize) as $row) {
            $rules[] = new AutomationTreeRuleSummary(
                (int) $row['id'],
                (string) $row['name'],
                (string) ($row['tree_name'] ?? ''),
                (string) ($row['subtree_name'] ?? ''),
                self::leafType((int) ($row['leaf_type'] ?? 0)),
                self::hostGrouping((int) ($row['host_grouping_type'] ?? 0)),
                (string) ($row['enabled'] ?? '') === 'on'
            );
        }

        return new AutomationTreeRulePage($rules, count($rows) > $criteria->pageSize);
    }

    private static function leafType(int $type): string
    {
        return match ($type) {
            0 => 'None',
            2 => 'Graph',
            3 => 'Device',
            default => 'Unknown',
        };
    }

    private static function hostGrouping(int $type): string
    {
        return match ($type) {
            1 => 'Graph Template',
            2 => 'Data Query Index',
            default => '',
        };
    }
}
