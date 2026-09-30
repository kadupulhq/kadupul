<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * With "Force Complexity Upon Old Passwords" on, a login whose correct
 * password fails the rules used to redirect and exit from inside
 * secpass_login_process(), before a session existed, so the user landed on
 * the login page again every time. An unknown username also reached that
 * redirect, which told it apart from a real one.
 */

require_once dirname(__DIR__, 3) . '/Helpers/LocalLoginProbe.php';

test('a correct password that fails the rules completes the login and flags a change', function () {
    $result = local_login_policy_run(local_login_policy_scenario('alice', 'weak'));
    $flag = local_login_policy_writes($result, '/must_change_password = \'on\'/');

    expect($result['headers'])->toBe(array())
        ->and($result['error'])->toBeFalse()
        ->and($result['user']['id'] ?? null)->toBe(42)
        ->and($flag)->toHaveCount(1)
        ->and($flag[0]['sql'])->toContain('WHERE id = ?')
        ->and($flag[0]['params'])->toBe(array(42))
        ->and($result['messages'])->toBe(array('forced_password'));
});

test('an unknown username and a wrong password for a real one get the same answer', function () {
    $unknown = local_login_policy_run(local_login_policy_scenario('nobody', 'weak'));
    $known = local_login_policy_run(local_login_policy_scenario('alice', 'wrong'));

    expect($unknown['headers'])->toBe(array())
        ->and($known['headers'])->toBe(array())
        ->and($unknown['error'])->toBeTrue()
        ->and($known['error'])->toBeTrue()
        ->and($unknown['user'])->toBe(array())
        ->and($known['user'])->toBe(array())
        ->and($unknown['messages'])->toBe($known['messages'])
        ->and(local_login_policy_writes($unknown, '/must_change_password/'))->toBe(array());
});

test('a correct password that meets the rules logs in without a forced change', function () {
    $scenario = local_login_policy_scenario('alice', 'Str0ngpass');
    $scenario['users'][0]['password'] = 'hash:Str0ngpass';

    $result = local_login_policy_run($scenario);

    expect($result['error'])->toBeFalse()
        ->and($result['user']['id'] ?? null)->toBe(42)
        ->and(local_login_policy_writes($result, '/must_change_password/'))->toBe(array())
        ->and($result['messages'])->toBe(array());
});

test('the rules are not checked when the option is off', function () {
    $result = local_login_policy_run(local_login_policy_scenario('alice', 'weak', array('config' => array('secpass_minlen' => 8))));

    expect($result['error'])->toBeFalse()
        ->and(local_login_policy_writes($result, '/must_change_password/'))->toBe(array());
});

test('an account that may not change its password is logged in without a forced change', function () {
    $scenario = local_login_policy_scenario('alice', 'weak');
    $scenario['users'][0]['password_change'] = '';

    $result = local_login_policy_run($scenario);

    expect($result['headers'])->toBe(array())
        ->and($result['error'])->toBeFalse()
        ->and(local_login_policy_writes($result, '/must_change_password/'))->toBe(array());
});
