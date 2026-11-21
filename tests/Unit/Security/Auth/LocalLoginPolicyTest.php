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

test('an account that may not change its password is refused like a wrong password', function () {
    $scenario = local_login_policy_scenario('alice', 'weak');
    $scenario['users'][0]['password_change'] = '';

    $refused = local_login_policy_run($scenario);
    $wrong = local_login_policy_run(local_login_policy_scenario('alice', 'wrong'));

    expect($refused['headers'])->toBe(array())
        ->and($refused['error'])->toBeTrue()
        ->and($refused['user'])->toBe(array())
        ->and($refused['error_msg'] ?? null)->toBe($wrong['error_msg'] ?? null)
        ->and($refused['messages'])->toBe($wrong['messages'])
        ->and(local_login_policy_writes($refused, '/must_change_password/'))->toBe(array());
});

test('post-verification policy retains lazy settings and bounded native writes', function (bool $forced, bool $compliant, bool $mayChange, mixed $expiry, bool $tracks) {
    $password = $compliant ? 'Str0ngpass' : 'weak';
    $scenario = local_login_policy_scenario('alice', $password);
    $scenario['users'][0]['password'] = 'hash:' . $password;
    $scenario['users'][0]['password_change'] = $mayChange ? 'on' : '';
    $scenario['config']['secpass_forceold'] = $forced ? 'on' : '';
    $scenario['config']['secpass_expireaccount'] = $expiry;
    $denied = $forced && !$compliant && !$mayChange;
    $change = $forced && !$compliant && $mayChange;
    $reads = array('secpass_forceold');
    if ($forced) {
        $reads[] = 'secpass_minlen';
        if ($compliant) {
            $reads = array_merge($reads, array('secpass_reqnum', 'secpass_reqmixcase', 'secpass_reqspec'));
        }
    }
    if (!$denied) {
        $reads[] = 'secpass_expireaccount';
    }

    $before = time();
    $result = local_login_policy_run($scenario);
    $after = time();
    expect($result['error'])->toBe($denied)
        ->and($result['setting_reads'])->toBe($reads)
        ->and($result['headers'])->toBe(array())
        ->and($result['messages'])->toBe($change ? array('forced_password') : array())
        ->and($result['executed'])->toHaveCount((int) $change + (int) (!$denied && $tracks));
    if ($change) {
        expect($result['executed'][0])->toBe(array(
            'sql' => "UPDATE user_auth SET must_change_password = 'on' WHERE id = ? AND realm = 0 AND enabled = 'on'",
            'params' => array(42),
        ));
    }
    if (!$denied && $tracks) {
        $write = $result['executed'][count($result['executed']) - 1];
        expect($write['sql'])->toBe("UPDATE user_auth SET lastlogin = ? WHERE username = ? AND realm = 0 AND enabled = 'on'")
            ->and($write['params'][1])->toBe('alice')
            ->and($write['params'][0])->toBeGreaterThanOrEqual($before)
            ->and($write['params'][0])->toBeLessThanOrEqual($after);
    }
})->with(array(
    'disabled policy and activity' => array(false, false, false, 0, false),
    'disabled policy records activity' => array(false, false, false, '1', true),
    'compliant without change permission' => array(true, true, false, 1, true),
    'compliant with empty expiry' => array(true, true, false, '', false),
    'compliant with zero string expiry' => array(true, true, true, '0', false),
    'compliant with negative expiry' => array(true, true, true, -1, false),
    'force change before activity' => array(true, false, true, '1', true),
    'force change without activity' => array(true, false, true, 0, false),
    'refuse before activity lookup' => array(true, false, false, 1, true),
));
