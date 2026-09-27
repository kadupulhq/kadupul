<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Persistence;

/**
 * Transitional projection of the legacy visibility rules, shared by the PDO
 * write path and the DBAL read path so the two cannot drift apart.
 * Identifiers and modes come from stored policies, never HTTP input. No
 * cached permission shortcut may bypass device exceptions.
 */
final class DeviceVisibilityRules
{
    public const MODE = "SELECT value FROM settings WHERE name = 'graph_auth_method'";
    public const USER_POLICY = "SELECT id, 'user' AS kind, policy_graphs, policy_hosts, policy_graph_templates FROM user_auth WHERE id = ?";
    public const GROUP_POLICY = "SELECT g.id, 'group' AS kind, g.policy_graphs, g.policy_hosts, g.policy_graph_templates
            FROM user_auth_group g JOIN user_auth_group_members m ON m.group_id = g.id WHERE m.user_id = ? AND g.enabled = 'on'";
    // Normal reads retain one statement snapshot for user/group policies.
    public const POLICIES = self::USER_POLICY . ' UNION ALL ' . self::GROUP_POLICY;

    public static function mode(mixed $stored): int
    {
        $mode = $stored === false ? 1 : (int) $stored;
        if (!in_array($mode, [1, 2, 3, 4], true)) {
            throw new \RuntimeException('Unsupported visibility policy.');
        }

        return $mode;
    }

    /**
     * @param list<array<string, mixed>> $policies rows selected by USER_POLICY and GROUP_POLICY
     * @param null|\Closure(string): string $exceptions turns an exception
     *        subquery into the SQL list placed inside IN (...); an empty
     *        string means the policy has no exceptions of that type
     */
    public static function predicate(int $mode, array $policies, ?\Closure $exceptions = null): string
    {
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
                $subquery = "SELECT item_id FROM $table WHERE $column = $id AND type = $type";
                $items = $exceptions === null ? $subquery : $exceptions($subquery);
                $clauses[] = $items === '' ? ($default === 1 ? '1 = 1' : '1 = 0') : "$field $operator ($items)";
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
