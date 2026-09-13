<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors				   |
 |																		   |
 | This program is free software; you can redistribute it and/or		   |
 | modify it under the terms of the GNU General Public License			   |
 | as published by the Free Software Foundation; either version 2		   |
 | of the License, or (at your option) any later version.				   |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution					   |
 +-------------------------------------------------------------------------+
*/

/*
 * A password login by a local user who must change their password sets
 * sess_change_password, and the next page sends them to auth_changepassword.php.
 * A remember-me login for the same user must land on that same flow, without
 * revoking the token or ending the session.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function remember_me_change_user(string $must_change, string $password_change = 'on', int $realm = 0) : array {
	return array(
		'id'				   => 42,
		'username'			   => 'alice',
		'realm'				   => $realm,
		'enabled'			   => 'on',
		'locked'			   => '',
		'password'			   => '',
		'must_change_password' => $must_change,
		'password_change'	   => $password_change,
	);
}

function remember_me_change_scenario(string $must_change, string $password_change = 'on', int $realm = 0) : array {
	return array(
		'config' => array('auth_method' => 1, 'auth_cache_enabled' => 'on'),
		'users'	 => array(remember_me_change_user($must_change, $password_change, $realm)),
		'cache'	 => array(array('user_id' => 42, 'token' => hash('sha512', 'valid-token'), 'hostname' => '192.0.2.10')),
		'cookie' => '42,' . $realm . ',valid-token',
	);
}

/* token rotation deletes one token by user and token; revocation would delete them all */
function remember_me_change_revocations(array $result) : array {
	return array_values(array_filter($result['executed'], function (array $row) : bool {
		return $row['sql'] === 'DELETE FROM user_auth_cache WHERE user_id = ?';
	}));
}

test('a remember-me login with a pending password change lands on the change password flow', function () {
	$result = cacti_test_run_auth_entry_probe(remember_me_change_scenario('on'));

	expect($result['session']['sess_user_id'] ?? null)->toBe(42)
		->and($result['session']['sess_change_password'] ?? null)->toBeTrue()
		->and($result['events'])->not->toContain('login_page')
		->and($result['page_continued'])->toBeFalse();
});

test('a remember-me login with a pending password change keeps the token and the session', function () {
	$result  = cacti_test_run_auth_entry_probe(remember_me_change_scenario('on'));
	$regular = cacti_test_run_auth_entry_probe(remember_me_change_scenario(''));

	/* the token rotates as on any remember-me login; nothing is revoked or destroyed */
	expect(remember_me_change_revocations($result))->toBe(array())
		->and($result['events'])->toBe($regular['events'])
		->and($result['events'])->toContain('cookie_set')
		->and($result['events'])->not->toContain('session_destroy');
});

test('a remember-me login ends where the page after a password login ends', function () {
	$cookie	  = cacti_test_run_auth_entry_probe(remember_me_change_scenario('on'));
	$password = cacti_test_run_auth_entry_probe(array(
		'config'  => array('auth_method' => 1, 'auth_cache_enabled' => 'on'),
		'users'	  => array(remember_me_change_user('on')),
		'session' => array('sess_user_id' => 42, 'sess_change_password' => true),
	));

	foreach (array($cookie, $password) as $result) {
		expect($result['session'])->toBe(array('sess_user_id' => 42, 'sess_change_password' => true))
			->and($result['events'])->not->toContain('login_page')
			->and($result['page_continued'])->toBeFalse();
	}
});

test('a remember-me login restores the session when no password change is pending', function () {
	$result = cacti_test_run_auth_entry_probe(remember_me_change_scenario(''));

	expect($result['session']['sess_user_id'] ?? null)->toBe(42)
		->and($result['session'])->not->toHaveKey('sess_change_password')
		->and($result['events'])->toContain('cookie_set')
		->and($result['page_continued'])->toBeTrue();
});

test('a remember-me login ignores the flag when the account may not change its password', function () {
	/* a password login only forces the change when password_change is on */
	$result = cacti_test_run_auth_entry_probe(remember_me_change_scenario('on', ''));

	expect($result['session'])->not->toHaveKey('sess_change_password')
		->and($result['page_continued'])->toBeTrue();
});

test('a remember-me login for a non-local realm ignores the local password change flag', function () {
	/* a password login only forces the change for local accounts */
	$result = cacti_test_run_auth_entry_probe(remember_me_change_scenario('on', 'on', 3));

	expect($result['session'])->not->toHaveKey('sess_change_password')
		->and($result['page_continued'])->toBeTrue();
});
