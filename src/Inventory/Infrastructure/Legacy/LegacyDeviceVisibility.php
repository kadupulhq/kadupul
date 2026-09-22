<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacyDeviceVisibility
{
    public function __construct(private DatabaseConnection $database) {}
    public function predicate(int $userId, bool $lock = false): string
    {
        // Transitional projection of the legacy visibility rules. Identifiers
        // and modes come from stored policies, never HTTP input. No cached
        // permission shortcut may bypass device exceptions.
        $db = $this->database->get();
        if ($lock && !$db->inTransaction()) {
            throw new \LogicException('Visibility locks require a transaction.');
        }
        $suffix = $lock ? ' LOCK IN SHARE MODE' : '';
        $mode = $db->query("SELECT value FROM settings WHERE name = 'graph_auth_method'" . $suffix)->fetchColumn();
        $mode = $mode === false ? 1 : (int) $mode;
        if (!in_array($mode, [1, 2, 3, 4], true)) {
            throw new \RuntimeException('Unsupported visibility policy.');
        }
        $userPolicy = "SELECT id, 'user' AS kind, policy_graphs, policy_hosts, policy_graph_templates FROM user_auth WHERE id = ?";
        $groupPolicy = "SELECT g.id, 'group' AS kind, g.policy_graphs, g.policy_hosts, g.policy_graph_templates
            FROM user_auth_group g JOIN user_auth_group_members m ON m.group_id = g.id WHERE m.user_id = ? AND g.enabled = 'on'";
        if ($lock) {
            // UNION does not reliably lock source rows; writes need current locks.
            $query = $db->prepare($userPolicy . $suffix);
            $query->execute([$userId]);
            $policies = $query->fetchAll();
            $query = $db->prepare($groupPolicy . $suffix);
            $query->execute([$userId]);
            $policies = array_merge($policies, $query->fetchAll());
        } else {
            // Normal reads retain one statement snapshot for user/group policies.
            $query = $db->prepare($userPolicy . ' UNION ALL ' . $groupPolicy);
            $query->execute([$userId, $userId]);
            $policies = $query->fetchAll();
        }
        $predicates = [];
        foreach ($policies as $policy) {
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
                if ($lock) {
                    // Materialize current locked exceptions instead of evaluating
                    // a non-locking subquery against an earlier MVCC snapshot.
                    $exceptions = $db->query("SELECT item_id FROM $table WHERE $column = $id AND type = $type" . $suffix)->fetchAll(\PDO::FETCH_COLUMN);
                    $ids = implode(',', array_map(intval(...), $exceptions));
                    $clauses[] = $ids === '' ? ($default === 1 ? '1 = 1' : '1 = 0') : "$field $operator ($ids)";
                } else {
                    $clauses[] = "$field $operator (SELECT item_id FROM $table WHERE $column = $id AND type = $type)";
                }
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
