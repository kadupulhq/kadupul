<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Copying a user group must carry over the source group's realms and
 * graph, tree, device and template permissions. The copy used to read them
 * from the new, empty group, so every copy came out with no access at all.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AdminActionProbe.php';

function group_copy_run(int $insert_id, array $perms, array $realms): array
{
    return admin_action_probe_run(array(
        'page' => 'user_group_admin.php',
        'functions' => array('user_group_copy'),
        'insert_id' => $insert_id,
        'answers' => array(
            array('assoc', '/FROM user_auth_group_perms WHERE group_id = \?/', $perms, array(5)),
            array('assoc', '/FROM user_auth_group_realm WHERE group_id = \?/', $realms, array(5)),
        ),
        'call' => "user_group_copy(5, 'Copy of')",
    ));
}

test('a copied group gets the source group\'s permissions and realms', function () {
    $result = group_copy_run(9, array(array('item_id' => 12, 'type' => 2), array('item_id' => 30, 'type' => 3)), array(array('realm_id' => 7), array('realm_id' => 8)));

    $perms = admin_action_probe_writes($result, '/^INSERT INTO user_auth_group_perms/');
    $realms = admin_action_probe_writes($result, '/^INSERT INTO user_auth_group_realm/');

    expect(array_column($perms, 'params'))->toBe(array(array(9, 12, 2), array(9, 30, 3)))
        ->and(array_column($realms, 'params'))->toBe(array(array(9, 7), array(9, 8)));
});

test('the new group row is copied from the source id', function () {
    $result = group_copy_run(9, array(), array());

    $insert = admin_action_probe_writes($result, '/^INSERT INTO user_auth_group \(/');

    expect($insert)->toHaveCount(1)
        ->and($insert[0]['sql'])->toContain("SELECT 'Copy of 1'")
        ->and($insert[0]['params'])->toBe(array(5));
});

test('a source group without grants produces a copy without grants', function () {
    $result = group_copy_run(9, array(), array());

    expect(admin_action_probe_writes($result, '/^INSERT INTO user_auth_group_(perms|realm)/'))->toBe(array());
});

test('a failed insert copies nothing further', function () {
    $result = group_copy_run(0, array(array('item_id' => 12, 'type' => 2)), array(array('realm_id' => 7)));

    expect(admin_action_probe_writes($result, '/^INSERT INTO user_auth_group_(perms|realm)/'))->toBe(array());
});
