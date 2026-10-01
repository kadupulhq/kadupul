<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * A live session caches its realms and tree permissions and drops the cache
 * only when user_auth.reset_perms changes. Group membership, group deletion,
 * policy and permission edits must therefore reset the affected users, or a
 * user keeps revoked access until they log out.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AdminActionProbe.php';

function group_reset_run(string $page, string $function, array $request, array $answers = array()): array
{
    return admin_action_probe_run(array(
        'page' => $page,
        'functions' => $page == 'user_group_admin.php' ? array($function, 'user_group_exists', 'user_group_refuse') : array($function),
        'request' => $request,
        'session' => array('sess_user_id' => 1),
        'answers' => array_merge(
            // A group that exists, so the existence guard passes.
            array(array('cell', '/FROM user_auth_group WHERE id = \?/', 1)),
            $answers
        ),
        'call' => $function . '()',
    ));
}

test('adding or removing a group member resets that member', function (string $action) {
    $result = group_reset_run('user_group_admin.php', 'form_actions', array(
        'associate_member' => 1, 'id' => 5, 'drp_action' => $action, 'chk_42' => 'on', 'chk_43' => 'on',
    ));

    expect($result['resets'])->toBe(array('user:42', 'user:43'));
})->with(array('add' => '1', 'remove' => '2'));

test('granting or revoking a group permission resets the group', function (string $flag) {
    $result = group_reset_run('user_group_admin.php', 'form_actions', array(
        $flag => 1, 'id' => 5, 'drp_action' => '2', 'chk_9' => 'on',
    ));

    expect($result['resets'])->toBe(array('group:5'));
})->with(array('associate_host', 'associate_graph', 'associate_template', 'associate_tree'));

test('a group policy change resets the group', function () {
    $result = group_reset_run('user_group_admin.php', 'update_policies', array('id' => 5, 'policy_trees' => 2, 'tab' => 'permstr'));

    expect($result['resets'])->toBe(array('group:5'));
});

test('removing one group permission resets the group', function () {
    $result = group_reset_run('user_group_admin.php', 'perm_remove', array('id' => 9, 'group_id' => 5, 'type' => 'tree'));

    expect($result['resets'])->toBe(array('group:5'));
});

test('deleting a group resets the members it had', function () {
    $result = admin_action_probe_run(array(
        'page' => 'user_group_admin.php',
        'functions' => array('user_group_remove'),
        'answers' => array(array('assoc', '/SELECT user_id FROM user_auth_group_members WHERE group_id = \? FOR UPDATE/', array(array('user_id' => 42), array('user_id' => 43)), array(5))),
        'call' => 'user_group_remove(5)',
    ));

    $deleted = admin_action_probe_writes($result, '/^DELETE FROM user_auth_group_members/');

    expect(array_column($result['executed'], 'sql')[0])->toBe('BEGIN')
        ->and(array_column($result['executed'], 'sql')[count($result['executed']) - 1])->toBe('COMMIT')
        ->and($result['reads'][0]['sql'])->toBe('SELECT id FROM user_auth_group WHERE id = ? FOR UPDATE')
        ->and($deleted)->toHaveCount(1)
        ->and($result['resets'])->toBe(array('user:42', 'user:43'));
});

test('deleting a group without members resets nobody', function () {
    $result = admin_action_probe_run(array(
        'page' => 'user_group_admin.php',
        'functions' => array('user_group_remove'),
        'call' => 'user_group_remove(5)',
    ));

    expect($result['resets'])->toBe(array());
});

test('changing a user\'s groups or permissions resets that user', function (string $flag) {
    $result = group_reset_run('user_admin.php', 'form_actions', array(
        $flag => 1, 'id' => 42, 'drp_action' => '2', 'chk_9' => 'on',
    ));

    expect($result['resets'])->toBe(array('user:42'));
})->with(array('associate_groups', 'associate_host', 'associate_graph', 'associate_template', 'associate_tree'));

test('a user policy change resets the user', function () {
    $result = group_reset_run('user_admin.php', 'update_policies', array('id' => 42, 'policy_graphs' => 2, 'tab' => 'permsg'));

    expect($result['resets'])->toBe(array('user:42'));
});

test('removing one user permission resets the user', function () {
    $result = group_reset_run('user_admin.php', 'perm_remove', array('id' => 9, 'user_id' => 42, 'type' => 'graph'));

    expect($result['resets'])->toBe(array('user:42'));
});

test('adding a permission from the graph permissions form resets the user', function (string $button) {
    $result = group_reset_run('user_admin.php', 'form_save', array(
        'save_component_graph_perms' => 1, 'id' => 42, $button => 1,
        'perm_graphs' => 9, 'perm_trees' => 9, 'perm_hosts' => 9, 'perm_graph_templates' => 9,
    ));

    expect($result['resets'])->toBe(array('user:42'));
})->with(array('add_graph_x', 'add_tree_x', 'add_host_x', 'add_graph_template_x'));
