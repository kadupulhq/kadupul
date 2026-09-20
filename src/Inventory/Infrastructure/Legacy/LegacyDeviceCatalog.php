<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Application\Port\DeviceCatalog;
use Kadupul\Inventory\Application\ReadModel\DevicePage;
use Kadupul\Inventory\Application\ReadModel\DeviceSummary;
use Kadupul\Inventory\Domain\DeviceListCriteria;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacyDeviceCatalog implements DeviceCatalog
{
    public function __construct(private DatabaseConnection $database) {}

    public function visibleTo(int $userId, DeviceListCriteria $criteria): DevicePage
    {
        $db = $this->database->get();
        $where = "h.id > 0 AND h.deleted = ''";
        $parameters = [];
        if ($criteria->search !== '') {
            $pattern = '%' . strtr($criteria->search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $where .= " AND (h.description LIKE ? ESCAPE '!' OR h.hostname LIKE ? ESCAPE '!')";
            $parameters = [$pattern, $pattern];
        }
        if ($criteria->state === 'disabled') {
            $where .= " AND h.disabled = 'on'";
        } elseif ($criteria->state === 'enabled') {
            $where .= " AND (h.disabled = '' OR h.disabled IS NULL)";
        }
        $where .= ' AND (' . $this->visibilityPredicate($userId) . ')';
        $query = $db->prepare("SELECT DISTINCT h.id, h.description, h.hostname, h.disabled, h.status
            FROM host h LEFT JOIN graph_local gl ON gl.host_id = h.id
            WHERE $where ORDER BY h.description ASC, h.id ASC LIMIT " . $criteria->offset() . ',' . ($criteria->pageSize + 1));
        $query->execute($parameters);
        $rows = $query->fetchAll();
        $hasNext = count($rows) > $criteria->pageSize;
        $devices = [];
        foreach (array_slice($rows, 0, $criteria->pageSize) as $row) {
            $devices[] = new DeviceSummary(
                (int) $row['id'],
                $row['description'],
                (string) $row['hostname'],
                $row['disabled'] === 'on',
                match ((int) $row['status']) {
                    1 => 'Down', 2 => 'Recovering', 3 => 'Up', default => 'Unknown'
                }
            );
        }

        return new DevicePage($devices, $hasNext);
    }
    private function visibilityPredicate(int $userId): string
    {
        // Transitional projection of the legacy visibility rules. Identifiers
        // and modes come from stored policies, never HTTP input. No cached
        // permission shortcut may bypass device exceptions.
        $db = $this->database->get();
        $mode = $db->query("SELECT value FROM settings WHERE name = 'graph_auth_method'")->fetchColumn();
        $mode = $mode === false ? 1 : (int) $mode;
        if (!in_array($mode, [1, 2, 3, 4], true)) {
            throw new \RuntimeException('Unsupported visibility policy.');
        }
        $query = $db->prepare("SELECT id, 'user' AS kind, policy_graphs, policy_hosts, policy_graph_templates FROM user_auth WHERE id = ?
            UNION ALL SELECT g.id, 'group' AS kind, g.policy_graphs, g.policy_hosts, g.policy_graph_templates
            FROM user_auth_group g JOIN user_auth_group_members m ON m.group_id = g.id WHERE m.user_id = ? AND g.enabled = 'on'");
        $query->execute([$userId, $userId]);
        $predicates = [];
        foreach ($query->fetchAll() as $policy) {
            $table = $policy['kind'] === 'user' ? 'user_auth_perms' : 'user_auth_group_perms';
            $column = $policy['kind'] === 'user' ? 'user_id' : 'group_id';
            $id = (int) $policy['id'];
            $clauses = [];
            foreach ([['gl.id', 1, 'policy_graphs'], ['h.id', 3, 'policy_hosts'], ['gl.graph_template_id', 4, 'policy_graph_templates']] as [$field, $type, $key]) {
                $default = (int) $policy[$key];
                if (!in_array($default, [1, 2], true)) {
                    throw new \RuntimeException('Unsupported visibility policy.');
                }
                $operator = $default === 1 ? 'NOT IN' : 'IN';
                $clauses[] = "$field $operator (SELECT item_id FROM $table WHERE $column = $id AND type = $type)";
            }
            [$graph, $device, $template] = $clauses;
            $predicates[] = match ($mode) {
                1 => "($graph OR $device OR $template)",
                2 => "($graph OR ($device AND $template))",
                3 => "($graph OR $device)",
                4 => "($graph OR $template)",
            };
        }
        return $predicates === [] ? '1 = 0' : implode(' OR ', $predicates);
    }

}
