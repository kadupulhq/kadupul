<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The user editor's Groups tab must add a user only to groups that exist. It
 * used to write a membership row for any posted group id, and a group created
 * later with that id then gave its realms and permissions to the user.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AdminActionProbe.php';

function user_group_membership_run(array $request, array $existing = array(5)): array
{
    $answers = array();

    foreach ($existing as $id) {
        $answers[] = array('cell', '/^SELECT id FROM user_auth_group WHERE id = \? FOR UPDATE$/', $id, array($id));
        $answers[] = array('cell', '/^SELECT COUNT\(\*\) FROM user_auth_group WHERE id = \?$/', 1, array($id));
    }

    return admin_action_probe_run(array(
        'permission_sql' => true,
        'permission_users' => array(7),
        'permission_groups' => $existing,
        'membership_rows' => ($request['drp_action'] ?? '') === '2' ? array(array(999,7)) : array(),
        'page' => 'user_admin.php',
        'functions' => array('form_actions'),
        'request' => $request + array('associate_groups' => 1, 'id' => 7),
        'session' => array('sess_user_id' => 1),
        'answers' => $answers,
        'call' => 'form_actions()',
    ));
}

test('adding a user to a missing group is refused without writing', function () {
    $result = user_group_membership_run(array('drp_action' => '1', 'chk_999' => 'on'));

    expect($result['memberships'])->toBe(array())
        ->and($result['messages'])->toBe(array(2))
        ->and($result['headers'])->toBe(array('Location: user_admin.php?action=user_edit&header=false&tab=permsgr&id=7'))
        ->and((int) $result['epochs'][7])->toBe(1);
});

test('one missing group stops the whole request', function () {
    $result = user_group_membership_run(array('drp_action' => '1', 'chk_5' => 'on', 'chk_999' => 'on'));

    expect($result['memberships'])->toBe(array())
        ->and($result['messages'])->toContain(2)->and((int) $result['epochs'][7])->toBe(1);
});

test('adding a user to an existing group writes through the parent group', function () {
    $result = user_group_membership_run(array('drp_action' => '1', 'chk_5' => 'on'));
    $write = admin_action_probe_writes($result, '/^REPLACE INTO user_auth_group_members/');

    expect($result['messages'])->toBe(array())
        ->and($write)->toHaveCount(1)
        ->and($write[0]['sql'])->toContain('VALUES (?, ?)')
        ->and($write[0]['params'])->toBe(array(7, 5))
        ->and($result['memberships'])->toBe(array(array('group_id' => 5, 'user_id' => 7)))
        ->and((int) $result['epochs'][7])->toBe(2);
});

test('removing a membership of a missing group still clears the row', function () {
    $result = user_group_membership_run(array('drp_action' => '2', 'chk_999' => 'on'));
    $delete = admin_action_probe_writes($result, '/^DELETE FROM user_auth_group_members/');

    expect($result['messages'])->toBe(array())
        ->and($delete)->toHaveCount(1)
        ->and($delete[0]['params'])->toBe(array(7, 999))
        ->and($result['memberships'])->toBe(array())->and((int) $result['epochs'][7])->toBe(2);
});
