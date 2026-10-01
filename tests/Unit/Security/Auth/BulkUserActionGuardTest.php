<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * User Management bulk actions must not remove, disable or overwrite the
 * primary administrator or the account doing the work. Batch Copy replaced
 * both without a check, Disable spared only the acting account, and Delete
 * skipped its protected-account check when the request named the account.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AdminActionProbe.php';

function bulk_user_run(string $action, array $selected, array $request = array()): array
{
    return admin_action_probe_run(array(
        'page' => 'user_admin.php',
        'functions' => array('form_actions'),
        'auth_functions' => array('user_remove', 'user_disable'),
        'request' => $request + array('selected_items' => serialize($selected), 'drp_action' => $action),
        'session' => array('sess_user_id' => 7),
        'config' => array('admin_user' => 1),
        // The real is_template_account() counts admin_user as protected.
        'globals' => array('template_accounts' => array('1')),
        'stubs' => <<<'PHP'
function get_guest_account() { return 0; }
function user_enable($user_id) {}
function user_copy(...$args) { $GLOBALS['executed'][] = array('sql' => 'user_copy', 'params' => $args); return true; }
PHP,
        'answers' => array(
            array('cell', '/^SELECT username FROM user_auth WHERE id = \?$/', 'admin', array(1)),
            array('row', '/FROM user_auth WHERE id = \? AND realm = 0$/', array('username' => 'template', 'realm' => 0), array(20)),
            array('row', '/^SELECT username, realm FROM user_auth WHERE id = \?$/', array('username' => 'alice', 'realm' => 0), array(9)),
            array('row', '/^SELECT username, realm FROM user_auth WHERE id = \?$/', array('username' => 'admin', 'realm' => 0), array(1)),
            array('row', '/^SELECT username, realm FROM user_auth WHERE id = \?$/', array('username' => 'operator', 'realm' => 0), array(7)),
        ),
        'call' => 'form_actions()',
    ));
}

test('deleting the primary administrator is refused even when the request names it', function () {
    $result = bulk_user_run('1', array(1), array('username' => 'admin'));

    expect(admin_action_probe_writes($result, '/^DELETE FROM user_auth WHERE/'))->toBe(array())
        ->and($result['messages'])->toContain(21);
});

test('deleting an ordinary account still works', function () {
    $result = bulk_user_run('1', array(9));

    expect(admin_action_probe_writes($result, '/^DELETE FROM user_auth WHERE id = \?$/'))->toHaveCount(1);
});

test('disabling the primary administrator or the acting account is refused', function (int $id, string $message) {
    $result = bulk_user_run('4', array($id));

    expect(admin_action_probe_writes($result, "/^UPDATE user_auth SET enabled = ''/"))->toBe(array())
        ->and($result['messages'])->toContain($message);
})->with(array('administrator' => array(1, 'attempt admin'), 'acting account' => array(7, 'attempt current')));

test('disabling an ordinary account still works', function () {
    $result = bulk_user_run('4', array(9));

    expect(admin_action_probe_writes($result, "/^UPDATE user_auth SET enabled = ''/"))->toHaveCount(1);
});

test('batch copy leaves the primary administrator and the acting account alone', function () {
    $result = bulk_user_run('5', array(1, 7, 9), array('template_user' => 20));
    $copies = admin_action_probe_writes($result, '/^user_copy$/');

    expect($copies)->toHaveCount(1)
        ->and($copies[0]['params'])->toBe(array('template', 'alice', 0, 0, true))
        ->and($result['messages'])->toContain('attempt admin')
        ->and($result['messages'])->toContain('attempt current')
        ->and($result['resets'])->toContain('user:9');
});

test('batch copy refuses a template that is not a local account', function () {
    $result = bulk_user_run('5', array(9), array('template_user' => 21));

    expect(admin_action_probe_writes($result, '/^user_copy$/'))->toBe(array())
        ->and($result['messages'])->toContain(2);
});
