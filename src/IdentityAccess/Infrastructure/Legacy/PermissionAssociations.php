<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

/** Shared selection writes; authenticated controllers retain their request gate. */
final class PermissionAssociations
{
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
            $changed = false;
            foreach ($_POST as $name => $value) {
                if (!preg_match('/^chk_([0-9]+)$/', $name, $matches)) {
                    continue;
                }
                input_validate_input_number($matches[1]);
                $parameters = array(get_nfilter_request_var('id'), $matches[1]);
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
                $written = db_execute_prepared($sql, $parameters);
                $changed = $written || $changed;
                if ($written && $group && $type === 0) {
                    reset_user_perms((int) $matches[1]);
                }
            }
            if ($changed && !$group) {
                reset_user_perms(get_filter_request_var('id'));
            } elseif ($changed && $type !== 0) {
                reset_group_perms(get_filter_request_var('id'));
            }
            return $tab;
        }
        return null;
    }
}
