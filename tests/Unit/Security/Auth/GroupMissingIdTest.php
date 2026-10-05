<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Group mutations must name a group that exists. A request carrying an
 * unknown group id used to write member, realm and permission rows for no
 * group; a group created later with that id then inherited them.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AdminActionProbe.php';

function group_guard_run(string $function, array $request, array $existing = array(5)): array
{
    $answers = array();

    foreach ($existing as $id) {
        $answers[] = array('cell', '/^SELECT id FROM user_auth_group WHERE id = \? FOR UPDATE$/', $id, array($id));
        $answers[] = array('cell', '/^SELECT COUNT\(\*\) FROM user_auth_group WHERE id = \?$/', 1, array($id));
    }

    return admin_action_probe_run(array(
        'page' => 'user_group_admin.php',
        'permission_sql' => true,
        'permission_groups' => $existing,
        'functions' => array($function, 'user_group_exists', 'user_group_refuse', 'user_group_remove', 'user_group_enable', 'user_group_disable', 'user_group_copy'),
        'request' => $request,
        'session' => array('sess_user_id' => 1),
        'globals' => array('settings_user' => array('general' => array('default_view_mode' => array()))),
        'answers' => $answers,
        'call' => $function . '()',
    ));
}

function group_guard_refused(array $result): bool
{
    return in_array('permission_denied', $result['messages'], true)
        && in_array('Location: user_group_admin.php?header=false', $result['headers'], true)
        && $result['executed'] === array();
}

dataset('group mutations', array(
    'add member' => array('form_actions', array('associate_member' => 1, 'drp_action' => '1', 'chk_42' => 'on')),
    'remove member' => array('form_actions', array('associate_member' => 1, 'drp_action' => '2', 'chk_42' => 'on')),
    'grant device' => array('form_actions', array('associate_host' => 1, 'drp_action' => '1', 'chk_9' => 'on')),
    'grant graph' => array('form_actions', array('associate_graph' => 1, 'drp_action' => '1', 'chk_9' => 'on')),
    'grant template' => array('form_actions', array('associate_template' => 1, 'drp_action' => '1', 'chk_9' => 'on')),
    'grant tree' => array('form_actions', array('associate_tree' => 1, 'drp_action' => '1', 'chk_9' => 'on')),
    'policy' => array('update_policies', array('policy_trees' => 2, 'tab' => 'permstr')),
    'realms' => array('form_save', array('save_component_realm_perms' => 1, 'section7' => 'on')),
    'settings' => array('form_save', array('save_component_graph_settings' => 1)),
    'rename' => array('form_save', array('save_component_group' => 1, 'name' => 'ops')),
));

test('a mutation naming a missing group is refused without writing', function (string $function, array $request) {
    $result = group_guard_run($function, $request + array('id' => 99));

    expect(group_guard_refused($result))->toBeTrue()
        ->and($result['logged'][0] ?? '')->toContain('missing User Group ID 99');
})->with('group mutations');

test('a mutation naming an existing group still writes', function (string $function, array $request) {
    $result = group_guard_run($function, $request + array('id' => 5));

    expect(in_array('permission_denied', $result['messages'], true))->toBeFalse()
        ->and($result['executed'])->not->toBe(array());
})->with('group mutations');

test('a membership or grant row is written only through the parent group', function () {
    $result = group_guard_run('form_actions', array('associate_member' => 1, 'drp_action' => '1', 'chk_42' => 'on', 'id' => 5));

    $write = admin_action_probe_writes($result, '/^REPLACE INTO user_auth_group_members/');

    expect($write[0]['sql'])->toContain('VALUES (?, ?)')
        ->and($write[0]['params'])->toBe(array(5, 42));
});

test('removing a permission from a missing group is refused', function () {
    $result = group_guard_run('perm_remove', array('id' => 9, 'group_id' => 99, 'type' => 'tree'));

    expect(group_guard_refused($result))->toBeTrue();
});

test('an empty group selection still validates its parent without writing', function (bool $existing) {
    $result = group_guard_run('form_actions', array('id' => $existing ? 5 : 99, 'associate_graph' => 1, 'drp_action' => '1'));
    expect($result['executed'])->toBe(array());
    if ($existing) {
        expect($result['messages'])->toBe(array())
            ->and($result['headers'])->toBe(array('Location: user_group_admin.php?action=edit&header=false&tab=permsg&id=5'));
    } else {
        expect(group_guard_refused($result))->toBeTrue();
    }
})->with(array('existing' => true, 'missing' => false));

test('creating a new group with id 0 is allowed', function () {
    $result = group_guard_run('form_save', array('save_component_group' => 1, 'id' => 0, 'name' => 'ops'), array());

    expect(in_array('permission_denied', $result['messages'], true))->toBeFalse()
        ->and(admin_action_probe_writes($result, '/^sql_save user_auth_group$/'))->toHaveCount(1);
});

test('a bulk action is refused when any selected group is missing', function (string $action) {
    $result = group_guard_run('form_actions', array('selected_items' => serialize(array(5, 99)), 'drp_action' => $action, 'group_prefix' => 'Copy'));

    expect(group_guard_refused($result))->toBeTrue();
})->with(array('delete' => '1', 'copy' => '2', 'enable' => '3', 'disable' => '4'));

test('a bulk action on existing groups proceeds', function () {
    $result = group_guard_run('form_actions', array('selected_items' => serialize(array(5)), 'drp_action' => '3'));

    expect(admin_action_probe_writes($result, "/^UPDATE user_auth_group SET enabled = 'on'/"))->toHaveCount(1);
});

test('a zero or negative id is never treated as a group', function ($id) {
    $result = group_guard_run('update_policies', array('id' => $id, 'policy_trees' => 2), array($id));

    expect(group_guard_refused($result))->toBeTrue();
})->with(array(0, -1));
