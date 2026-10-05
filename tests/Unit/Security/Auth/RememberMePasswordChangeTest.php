<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * A password login by a local user with a pending forced change lands on the
 * change page. A session restored from the remember-me cookie must too, or the
 * cookie outlives a reset an administrator forced after a compromise.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function remember_me_scenario(array $user): array
{
    $token = 'remember-me-token';

    return array(
        'config' => array('auth_method' => 1, 'auth_cache_enabled' => 'on'),
        'cookie' => $user['id'] . ',' . $user['realm'] . ',' . $token,
        'users' => array($user + array('username' => 'alice', 'enabled' => 'on', 'locked' => '', 'password' => 'stored-hash', 'lastfail' => 0, 'failed_attempts' => 0)),
        'cache' => array(array('user_id' => $user['id'], 'token' => hash('sha512', $token), 'hostname' => '192.0.2.10')),
    );
}

test('a remember-me login with a pending forced change goes to the change page', function () {
    $result = auth_entry_probe_run(remember_me_scenario(array('id' => 42, 'realm' => 0, 'must_change_password' => 'on', 'password_change' => 'on')));

    expect($result['session']['sess_user_id'] ?? null)->toBe(42)
        ->and($result['session']['sess_change_password'] ?? null)->toBeTrue()
        ->and($result['page_continued'])->toBeFalse();
});

test('a remember-me login without a pending change continues to the page', function () {
    $result = auth_entry_probe_run(remember_me_scenario(array('id' => 42, 'realm' => 0, 'must_change_password' => '', 'password_change' => 'on')));

    expect($result['session']['sess_user_id'] ?? null)->toBe(42)
        ->and($result['session'])->not->toHaveKey('sess_change_password')
        ->and($result['page_continued'])->toBeTrue()
        ->and($result['session']['sess_remember_token']['user_id'])->toBe(42)
        ->and($result['session']['sess_remember_token']['hash'])->toMatch('/^[0-9a-f]{128}$/');
});

test('the change is only required where a password login would require it', function (int $realm, string $allowed) {
    $result = auth_entry_probe_run(remember_me_scenario(array('id' => 42, 'realm' => $realm, 'must_change_password' => 'on', 'password_change' => $allowed)));

    expect($result['session']['sess_user_id'] ?? null)->toBe(42)
        ->and($result['session'])->not->toHaveKey('sess_change_password')
        ->and($result['page_continued'])->toBeTrue();
})->with(array(
    'directory account' => array(3, 'on'),
    'password changes not allowed' => array(0, ''),
));

test('an unknown remember-me token still restores nothing', function () {
    $scenario = remember_me_scenario(array('id' => 42, 'realm' => 0, 'must_change_password' => 'on', 'password_change' => 'on'));
    $scenario['cookie'] = '42,0,forged-token';

    $result = auth_entry_probe_run($scenario);

    expect($result['session'])->toBe(array())
        ->and($result['events'])->toContain('login_page');
});

test('a malformed remember-me cookie sends the visitor to the login page without an error', function ($cookie) {
    $scenario = remember_me_scenario(array('id' => 42, 'realm' => 0, 'must_change_password' => '', 'password_change' => 'on'));
    $scenario['cookie'] = $cookie;

    $result = auth_entry_probe_run($scenario);

    expect($result['stderr'])->toBe('')
        ->and($result['session'])->toBe(array())
        ->and($result['events'])->toContain('login_page');
})->with(array(
    'array' => array(array('42', '0', 'remember-me-token')),
    'one part' => '42',
    'four parts' => '42,0,remember-me-token,extra',
));

test('remember cookie issuance returns its documented boolean outcome', function (bool $table) {
    $result = auth_entry_probe_run(array(
        'cache_table' => $table,
        'call' => array('type' => 'set_auth_cookie', 'args' => array(array('id' => 42, 'realm' => 0))),
    ));
    expect($result['return'])->toBe($table)
        ->and(in_array('cookie_set', $result['events'], true))->toBe($table);
})->with(array(true, false));
