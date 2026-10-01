<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * An install still set to the retired "no authentication" method is moved to
 * local authentication. The request that triggers the move must not receive a
 * session, and the administrator signs in normally afterwards.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function no_auth_updates(array $result): array
{
    return array_values(array_filter($result['executed'], function (array $row): bool {
        return strpos($row['sql'], 'UPDATE user_auth') !== false;
    }));
}

function no_auth_admin(int $id, string $enabled = 'on'): array
{
    return array('id' => $id, 'username' => 'admin' . $id, 'realm' => 0, 'enabled' => $enabled, 'locked' => '', 'password' => 'stored-hash');
}

test('leaving no authentication starts no session for the request', function () {
    $result = auth_entry_probe_run(array(
        'config' => array('auth_method' => 0, 'admin_user' => 5),
        'users' => array(no_auth_admin(5)),
    ));

    expect($result['session'])->not->toHaveKey('sess_user_id')
        ->and($result['session'])->not->toHaveKey('sess_change_password')
        ->and($result['events'])->not->toContain('cookie_set')
        ->and($result['config_writes'])->toBe(array(array('auth_method', 1)))
        ->and($result['page_continued'])->toBeFalse();
});

test('the administrator keeps the stored password and must change it at the next login', function () {
    $result = auth_entry_probe_run(array(
        'config' => array('auth_method' => 0, 'admin_user' => 5),
        'users' => array(no_auth_admin(1), no_auth_admin(5)),
    ));

    $updates = no_auth_updates($result);

    expect($updates)->toHaveCount(1)
        ->and($updates[0]['sql'])->not->toContain('password = \'\'')
        ->and($updates[0]['sql'])->toContain("must_change_password = 'on'")
        ->and($updates[0]['sql'])->toContain("password_change = 'on'")
        ->and(array_map('strval', $updates[0]['params']))->toBe(array('5'));
});

test('a disabled configured administrator is passed over for an enabled settings user', function () {
    $result = auth_entry_probe_run(array(
        'config' => array('auth_method' => 0, 'admin_user' => 5),
        'users' => array(no_auth_admin(5, ''), no_auth_admin(7)),
        'realms' => array(array(5, 15), array(7, 15)),
    ));

    $updates = no_auth_updates($result);

    expect($updates)->toHaveCount(1)
        ->and(array_map('strval', $updates[0]['params']))->toBe(array('7'))
        ->and($result['session'])->not->toHaveKey('sess_user_id');
});

test('the fallback picks the lowest enabled settings user with MySQL syntax', function () {
    $result = auth_entry_probe_run(array(
        'config' => array('auth_method' => 0, 'admin_user' => 99),
        'users' => array(no_auth_admin(3, ''), no_auth_admin(8), no_auth_admin(7)),
        'realms' => array(array(3, 15), array(8, 15), array(7, 15)),
    ));

    $updates = no_auth_updates($result);

    expect($updates)->toHaveCount(1)
        ->and(array_map('strval', $updates[0]['params']))->toBe(array('7'));
});

test('the fallback finds a settings user through an enabled group', function () {
    $result = auth_entry_probe_run(array(
        'config' => array('auth_method' => 0, 'admin_user' => 99),
        'users' => array(array('id' => 9, 'username' => 'grp', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'stored-hash')),
        'realms' => array(),
        'groups' => array(array(4, 'on')),
        'group_members' => array(array(4, 9)),
        'group_realms' => array(array(4, 15)),
    ));

    $updates = no_auth_updates($result);

    expect($updates)->toHaveCount(1)
        ->and(array_map('strval', $updates[0]['params']))->toBe(array('9'));
});

test('without an administrator account the install still leaves no authentication', function () {
    $result = auth_entry_probe_run(array(
        'config' => array('auth_method' => 0, 'admin_user' => 5),
        'users' => array(),
        'realms' => array(),
    ));

    expect($result['session'])->not->toHaveKey('sess_user_id')
        ->and($result['config_writes'])->toBe(array(array('auth_method', 1)))
        ->and(no_auth_updates($result))->toBe(array())
        ->and($result['log'])->toContain('ERROR: Authentication was previously not set.  Attempted to set to Local Authentication, but no Administrative account was found.')
        ->and($result['page_continued'])->toBeFalse();
});

test('an install already on local authentication is left alone', function () {
    $result = auth_entry_probe_run(array(
        'config' => array('auth_method' => 1, 'admin_user' => 5),
        'users' => array(no_auth_admin(5)),
        'session' => array('sess_user_id' => 5),
    ));

    expect($result['config_writes'])->toBe(array())
        ->and(no_auth_updates($result))->toBe(array())
        ->and($result['page_continued'])->toBeTrue();
});


test('recovery skips non-local configured and realm administrators', function () {
    $directory = no_auth_admin(5);
    $directory['realm'] = 1;
    $result = auth_entry_probe_run(array('config' => array('auth_method' => 0, 'admin_user' => 5), 'users' => array($directory, no_auth_admin(7)), 'realms' => array(array(5, 15), array(7, 15))));
    expect(array_map('strval', no_auth_updates($result)[0]['params']))->toBe(array('7'));
});

test('recovery skips a non-local group administrator', function () {
    $directory = no_auth_admin(5);
    $directory['realm'] = 2;
    $result = auth_entry_probe_run(array('config' => array('auth_method' => 0, 'admin_user' => 99), 'users' => array($directory, no_auth_admin(7)), 'realms' => array(array(7, 15)), 'groups' => array(array(4, 'on')), 'group_members' => array(array(4, 5)), 'group_realms' => array(array(4, 15))));
    expect(array_map('strval', no_auth_updates($result)[0]['params']))->toBe(array('7'));
});

test('the final admin-name fallback rejects an ineligible account', function (string $enabled, int $realm) {
    $admin = no_auth_admin(5, $enabled);
    $admin['username'] = 'admin';
    $admin['realm'] = $realm;
    $result = auth_entry_probe_run(array('config' => array('auth_method' => 0, 'admin_user' => 99), 'users' => array($admin), 'realms' => array()));
    expect(no_auth_updates($result))->toBe(array());
})->with(array(array('', 0), array('on', 1)));


test('recovery rejects empty or locked credentials in every candidate path', function (string $path, string $password, string $locked) {
    $bad = no_auth_admin(5);
    $bad['password'] = $password;
    $bad['locked'] = $locked;
    if ($path === 'final') {
        $bad['username'] = 'admin';
    }
    $scenario = array('config' => array('auth_method' => 0, 'admin_user' => $path === 'configured' ? 5 : 99), 'users' => array($bad, no_auth_admin(7)), 'realms' => array(array(7, 15)));
    if ($path === 'direct') {
        $scenario['realms'][] = array(5, 15);
    }
    if ($path === 'group') {
        $scenario += array('groups' => array(array(4, 'on')), 'group_members' => array(array(4, 5)), 'group_realms' => array(array(4, 15)));
    }
    if ($path === 'final') {
        $scenario['realms'] = array();
        $scenario['users'] = array($bad);
    }
    $result = auth_entry_probe_run($scenario);
    if ($path === 'final') {
        expect(no_auth_updates($result))->toBe(array());
    } else {
        expect(array_map('strval', no_auth_updates($result)[0]['params']))->toBe(array('7'));
    }
})->with(array('configured', 'direct', 'group', 'final'))->with(array(array('', ''), array('stored-hash', 'on')));
