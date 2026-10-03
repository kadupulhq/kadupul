<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

require_once __DIR__ . '/PermissionMutation.php';

/** Shared selection writes; authenticated controllers retain their request gate. */
final class PermissionAssociations
{
    /** The first submitted add button wins, as in the authenticated user form. */
    public static function addUserPermission(): ?bool
    {
        foreach (array('add_graph_x' => array(1, 'perm_graphs'), 'add_tree_x' => array(2, 'perm_trees'), 'add_host_x' => array(3, 'perm_hosts'), 'add_graph_template_x' => array(4, 'perm_graph_templates')) as $flag => [$type, $field]) {
            if (isset_request_var($flag)) {
                return self::writePermission(false, true, $type, get_nfilter_request_var('id'), get_nfilter_request_var($field));
            }
        }
        return null;
    }

    /** Unknown removal types retain the controller's successful no-op redirect. */
    public static function removePermission(bool $group): bool
    {
        foreach (array('graph' => 1, 'tree' => 2, 'host' => 3, 'graph_template' => 4) as $name => $type) {
            if (get_request_var('type') == $name) {
                return self::writePermission($group, false, $type, get_request_var($group ? 'group_id' : 'user_id'), get_request_var('id'));
            }
        }
        return true;
    }

    private static function writePermission(bool $group, bool $associate, int $type, mixed $principal, mixed $item): bool
    {
        $table = $group ? 'user_auth_group_perms' : 'user_auth_perms';
        $column = $group ? 'group_id' : 'user_id';
        $sql = $associate
            ? 'REPLACE INTO ' . $table . ' (' . $column . ', item_id, type) VALUES (?, ?, ?)'
            : 'DELETE FROM ' . $table . ' WHERE ' . $column . ' = ? AND item_id = ? AND type = ?';
        return PermissionMutation::write($sql, array($principal, $item, $type), $group, (int) $principal);
    }

    public static function apply(bool $group): ?string
    {
        $choices = $group
            ? array('associate_host' => array(3, 'permsd'), 'associate_graph' => array(1, 'permsg'), 'associate_template' => array(4, 'permste'), 'associate_tree' => array(2, 'permstr'), 'associate_member' => array(0, 'members'))
            : array('associate_host' => array(3, 'permsd'), 'associate_graph' => array(1, 'permsg'), 'associate_template' => array(4, 'permste'), 'associate_groups' => array(0, 'permsgr'), 'associate_tree' => array(2, 'permstr'));
        foreach ($choices as $flag => [$type, $tab]) {
            if (!isset_request_var($flag)) {
                continue;
            }
            $associate = get_nfilter_request_var('drp_action') == '1';
            $selected = array();
            foreach ($_POST as $name => $value) {
                if (!preg_match('/^chk_([0-9]+)$/', $name, $matches)) {
                    continue;
                }
                input_validate_input_number($matches[1]);
                $selected[] = (int) $matches[1];
            }
            $principal = (int) get_filter_request_var('id');
            $table = $type === 0 ? 'user_auth_group_members' : ($group ? 'user_auth_group_perms' : 'user_auth_perms');
            $tables = array_unique(array('user_auth', $table, ...($group || $type === 0 ? array('user_auth_group', 'user_auth_group_members') : array())));
            $groups = $group ? array($principal) : ($type === 0 ? $selected : array());
            $failed = false;
            if ($selected !== array()) {
                PermissionMutation::batch($tables, $groups, static function () use ($selected, $principal, $type, $group, $associate, &$failed): void {
                    foreach ($selected as $item) {
                        $parameters = array($principal, $item);
                        if ($type === 0) {
                            $sql = $group
                                ? ($associate ? 'REPLACE INTO user_auth_group_members (group_id, user_id) VALUES (?, ?)' : 'DELETE FROM user_auth_group_members WHERE group_id = ? AND user_id = ?')
                                : ($associate ? 'REPLACE INTO user_auth_group_members (user_id, group_id) VALUES (?, ?)' : 'DELETE FROM user_auth_group_members WHERE user_id = ? AND group_id = ?');
                        } else {
                            $sql = $group
                                ? ($associate ? 'REPLACE INTO user_auth_group_perms (group_id, item_id, type) VALUES (?, ?, ?)' : 'DELETE FROM user_auth_group_perms WHERE group_id = ? AND item_id = ? AND type = ?')
                                : ($associate ? 'REPLACE INTO user_auth_perms (user_id, item_id, type) VALUES (?, ?, ?)' : 'DELETE FROM user_auth_perms WHERE user_id = ? AND item_id = ? AND type = ?');
                            $parameters[] = $type;
                        }
                        if (!PermissionMutation::write($sql, $parameters, $group, $principal, $group && $type === 0 ? $item : null)) {
                            $failed = true;
                        }
                    }
                });
            }
            if ($failed) {
                \raise_message(2);
            }
            return $tab;
        }
        return null;
    }
}
