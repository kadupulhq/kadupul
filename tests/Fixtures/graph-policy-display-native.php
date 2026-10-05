<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Translation and request ports; policy functions and SQL run unchanged. */
function __(string $message, mixed ...$arguments): string
{
    return $arguments ? vsprintf($message, $arguments) : $message;
}

function __esc(string $message, mixed ...$arguments): string
{
    return htmlspecialchars(__($message, ...$arguments), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function get_request_var(string $name): int
{
    return 42;
}

function graph_policy_display_fixture_run(): array
{
    global $db, $scenario;
    $db->exec("INSERT INTO user_auth_group(id,enabled,name,policy_graphs,policy_hosts,policy_graph_templates) VALUES(7,'on','stored policy group',2,2,2);
        INSERT INTO user_auth_group_members VALUES(42,7);");
    $resources = [];
    for ($userMask = 0; $userMask < 8; $userMask++) {
        for ($groupMask = 0; $groupMask < 8; $groupMask++) {
            $id = 1000 + $userMask * 8 + $groupMask;
            $host = 10000 + $id;
            $template = 20000 + $id;
            $db->prepare('INSERT INTO host(id,description) VALUES(?,?)')->execute([$host, 'Stored host ' . $id]);
            $db->prepare('INSERT INTO graph_templates VALUES(?,?)')->execute([$template, 'Stored template ' . $id]);
            $db->prepare('INSERT INTO graph_local(id,host_id,graph_template_id) VALUES(?,?,?)')->execute([$id, $host, $template]);
            $db->prepare('INSERT INTO graph_templates_graph VALUES(?,?,100,100)')->execute([$id, 'Stored graph ' . $id]);
            foreach ([1 => $id, 3 => $host, 4 => $template] as $type => $item) {
                $bit = [1 => 1, 3 => 2, 4 => 4][$type];
                if ($userMask & $bit) $db->prepare('INSERT INTO user_auth_perms VALUES(42,?,?)')->execute([$type, $item]);
                if ($groupMask & $bit) $db->prepare('INSERT INTO user_auth_group_perms VALUES(7,?,?)')->execute([$type, $item]);
            }
            $resources[$id] = [$userMask, $groupMask];
        }
    }
    $comparisons = [];
    for ($userDefaults = 0; $userDefaults < 8; $userDefaults++) {
        for ($groupDefaults = 0; $groupDefaults < 8; $groupDefaults++) {
            $defaults = static fn(int $mask): array => [($mask & 1) ? 2 : 1, ($mask & 2) ? 2 : 1, ($mask & 4) ? 2 : 1];
            $db->prepare('UPDATE user_auth SET policy_graphs=?,policy_hosts=?,policy_graph_templates=?,reset_perms=reset_perms+1 WHERE id=42')->execute($defaults($userDefaults));
            $db->prepare('UPDATE user_auth_group SET policy_graphs=?,policy_hosts=?,policy_graph_templates=? WHERE id=7')->execute($defaults($groupDefaults));
            // The UI displays user then group policies using these native joins.
            $policies = array_reverse(get_policies(42));
            $joins = get_policy_join_select($policies);
            $rows = db_fetch_assoc('SELECT gl.id AS local_graph_id,h.disabled,' . $joins['sql_select'] . '
                FROM graph_local AS gl LEFT JOIN host AS h ON h.id=gl.host_id
                LEFT JOIN graph_templates AS gt ON gt.id=gl.graph_template_id ' . $joins['sql_join'] . ' ORDER BY gl.id');
            if (count($rows) !== 64) throw new RuntimeException('Policy display did not discover the complete stored graph set.');
            $total = -1;
            $allowed = array_fill_keys(array_column(get_allowed_graphs('', '', '', $total, 42), 'local_graph_id'), true);
            $where = get_policy_where($scenario['config']['graph_auth_method'], $policies, 'WHERE 1=1');
            $filtered = array_fill_keys(array_column(db_fetch_assoc('SELECT gl.id FROM graph_local AS gl
                LEFT JOIN host AS h ON h.id=gl.host_id LEFT JOIN graph_templates AS gt ON gt.id=gl.graph_template_id ' . $where), 'id'), true);
            foreach ($rows as $row) {
                $id = (int) $row['local_graph_id'];
                $comparisons[] = ['defaults' => [$userDefaults, $groupDefaults], 'exceptions' => $resources[$id],
                    'display' => str_contains(get_permission_string($row, $policies), "class='accessGranted'"),
                    'allowed' => isset($allowed[$id]), 'filtered' => isset($filtered[$id])];
            }
        }
    }
    return $comparisons;
}
