<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Every anonymous visitor shares the guest account, so Edit Profile must not
 * let one of them rewrite what the others see.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function guest_profile_scenario(string $page, array $session): array
{
    return array(
        'page' => $page,
        'guest_account' => true,
        'session' => $session,
        'config' => array('auth_method' => 1, 'guest_user' => 'guest'),
        'users' => array(
            array('id' => 1, 'username' => 'admin', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'stored-hash'),
            array('id' => 3, 'username' => 'guest', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => ''),
        ),
        'realm_filenames' => array('auth_profile.php' => -1, 'graph_view.php' => -1),
    );
}

test('an anonymous visitor is sent to the login page instead of the guest profile', function () {
    $result = auth_entry_probe_run(guest_profile_scenario('auth_profile.php', array()));

    expect($result['events'])->toContain('login_page')
        ->and($result['session'])->not->toHaveKey('sess_user_id')
        ->and($result['page_continued'])->toBeFalse();
});

test('a guest session loses its credentials on the profile page', function () {
    $result = auth_entry_probe_run(guest_profile_scenario('auth_profile.php', array('sess_user_id' => '3')));

    expect($result['events'])->toContain('session_destroy')
        ->and($result['events'])->toContain('login_page')
        ->and($result['session'])->not->toHaveKey('sess_user_id')
        ->and($result['page_continued'])->toBeFalse();
});

test('a signed-in user still reaches their own profile', function () {
    $result = auth_entry_probe_run(guest_profile_scenario('auth_profile.php', array('sess_user_id' => 1)));

    expect($result['page_continued'])->toBeTrue()
        ->and($result['session']['sess_user_id'])->toBe(1)
        ->and($result['events'])->not->toContain('login_page');
});

test('guest pages other than the profile still admit anonymous visitors', function () {
    $result = auth_entry_probe_run(guest_profile_scenario('graph_view.php', array()));

    expect($result['page_continued'])->toBeTrue()
        ->and((string) $result['session']['sess_user_id'])->toBe('3');
});
