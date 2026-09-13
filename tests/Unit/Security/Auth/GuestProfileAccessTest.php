<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Every anonymous visitor shares the guest account, so the guest may not open
 * Edit Profile. Signed-in users keep the guest page exemption auth_profile.php
 * has always had, with or without the profile realm.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

/* ids are strings because the database layer returns them that way */
function guest_profile_request(string $page, array $session = array(), array $realms = array()) : array {
	return array(
		'config'          => array('auth_method' => 1, 'guest_user' => 'guest'),
		'users'           => array(
			array('id' => '3', 'username' => 'guest', 'realm' => 0, 'enabled' => '', 'locked' => ''),
			array('id' => '42', 'username' => 'alice', 'realm' => 0, 'enabled' => 'on', 'locked' => ''),
		),
		'realms'          => $realms,
		'page'            => $page,
		'realm_filenames' => array('graph_view.php' => 7, 'auth_profile.php' => 20),
		'guest_account'   => true,
		'session'         => $session,
	);
}

test('an anonymous visitor is sent to the login page instead of the guest profile', function () {
	$result = cacti_test_run_auth_entry_probe(guest_profile_request('auth_profile.php', array(), array(array(3, 20))));

	expect($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['events'])->toContain('login_page')
		->and($result['page_continued'])->toBeFalse();
});

test('a guest session is ended rather than allowed into the guest profile', function () {
	$result = cacti_test_run_auth_entry_probe(guest_profile_request('auth_profile.php', array('sess_user_id' => '3'), array(array(3, 20))));

	expect($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['events'])->toContain('session_destroy')
		->and($result['events'])->toContain('login_page')
		->and($result['page_continued'])->toBeFalse();
});

test('a signed-in user with the profile realm still opens the profile', function () {
	$result = cacti_test_run_auth_entry_probe(guest_profile_request('auth_profile.php', array('sess_user_id' => '42'), array(array(42, 20))));

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['events'])->not->toContain('error_page')
		->and($result['page_continued'])->toBeTrue();
});

test('a signed-in user without the profile realm opens the profile as in 1.2.31', function () {
	/* auth_profile.php is a guest page, so a signed-in user skips the realm check */
	$result = cacti_test_run_auth_entry_probe(guest_profile_request('auth_profile.php', array('sess_user_id' => '42')));

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['events'])->not->toContain('error_page')
		->and($result['events'])->not->toContain('login_page')
		->and($result['page_continued'])->toBeTrue();
});

test('an anonymous visitor still views other guest pages as the guest', function () {
	$result = cacti_test_run_auth_entry_probe(guest_profile_request('graph_view.php'));

	expect($result['session']['sess_user_id'] ?? null)->toBe('3')
		->and($result['events'])->not->toContain('error_page')
		->and($result['page_continued'])->toBeTrue();
});

test('a guest session still views other guest pages', function () {
	$result = cacti_test_run_auth_entry_probe(guest_profile_request('graph_view.php', array('sess_user_id' => '3')));

	expect($result['session']['sess_user_id'] ?? null)->toBe('3')
		->and($result['page_continued'])->toBeTrue();
});
