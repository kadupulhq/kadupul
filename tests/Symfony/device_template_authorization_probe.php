<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require getcwd() . '/include/vendor/autoload.php';

use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceVisibility;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\InstallationDatabase;

$database = new InstallationDatabase(new InstallationConfiguration(getcwd()));
$db = $database->get();
$rival = (new InstallationDatabase(new InstallationConfiguration(getcwd())))->get();
$rival->exec('SET SESSION innodb_lock_wait_timeout = 1');
$actor = (int) $argv[1];
$device = (int) $argv[2];
$group = 0;
$results = [];
$visibility = new LegacyDeviceVisibility($database);
try {
    $rival->exec("INSERT INTO user_auth_group (name,enabled,policy_graphs,policy_hosts,policy_graph_templates) VALUES ('template-lock','on',2,2,2)");
    $group = (int) $rival->lastInsertId();
    $rival->exec("INSERT INTO user_auth_group_members (group_id,user_id) VALUES ($group,$actor)");
    $rival->exec("INSERT INTO user_auth_group_perms (group_id,item_id,type) VALUES ($group,$device,3)");
    $mutations = [
        'mode' => "UPDATE settings SET value='4' WHERE name='graph_auth_method'",
        'actor_policy' => "UPDATE user_auth SET policy_hosts=2 WHERE id=$actor",
        'group_policy' => "UPDATE user_auth_group SET policy_hosts=1 WHERE id=$group",
        'membership' => "DELETE FROM user_auth_group_members WHERE group_id=$group AND user_id=$actor",
        'group_enabled' => "UPDATE user_auth_group SET enabled='' WHERE id=$group",
        'group_exception' => "DELETE FROM user_auth_group_perms WHERE group_id=$group AND item_id=$device AND type=3",
        'new_actor_exception' => "INSERT INTO user_auth_perms (user_id,item_id,type) VALUES ($actor,16777214,3)",
        'new_group_exception' => "INSERT INTO user_auth_group_perms (group_id,item_id,type) VALUES ($group,16777214,3)",
    ];
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->beginTransaction();
    $visibility->predicate($actor, true);
    foreach ($mutations as $name => $sql) {
        $rival->beginTransaction();
        try {
            $rival->exec($sql);
            $results[$name] = false;
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) !== 1205) {
                throw $error;
            }
            $results[$name] = true;
        } finally {
            $rival->rollBack();
        }
    }
    $db->rollBack();
    // Establish an old snapshot, revoke the group exception on another connection,
    // then verify the locked predicate contains current committed permissions.
    $db->beginTransaction();
    $visibility->predicate($actor);
    $rival->exec("DELETE FROM user_auth_group_perms WHERE group_id=$group AND item_id=$device AND type=3");
    $current = $visibility->predicate($actor, true);
    $results['current_permissions'] = !str_contains($current, "h.id IN ($device)");
    $db->rollBack();
    foreach ($mutations as $name => $sql) {
        if ($name === 'group_exception') {
            continue;
        }
        $rival->beginTransaction();
        $rival->exec($sql);
        $rival->rollBack();
        $results[$name . '_released'] = true;
    }
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($rival->inTransaction()) {
        $rival->rollBack();
    }
    if ($group) {
        $rival->exec("DELETE FROM user_auth_group_perms WHERE group_id=$group");
        $rival->exec("DELETE FROM user_auth_group_members WHERE group_id=$group");
        $rival->exec("DELETE FROM user_auth_group WHERE id=$group");
    }
}
echo json_encode($results, JSON_THROW_ON_ERROR);
