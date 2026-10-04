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
*/

/*
 * The server kept a session for as long as PHP's garbage collector left it,
 * which can be far longer than session.gc_maxlifetime when the collector
 * runs late or not at all, and honoured a remember-me row for the 90 days
 * before maintenance purged it although its cookie expires after 30.
 *
 * A session now ends once it has been idle longer than
 * session.gc_maxlifetime, the limit the collector and the page's own logout
 * timer already use, and a remember-me row older than 30 days is ignored.
 * The shipped include/auth.php and lib/auth.php run through the auth entry
 * probe.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function session_lifetime_request(array $session, array $extra = array()) : array {
	return $extra + array(
		'config'  => array('auth_method' => 1, 'guest_user' => 'guest'),
		'users'   => array(
			array('id' => '42', 'username' => 'alice', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'hash', 'must_change_password' => '', 'password_change' => 'on'),
			array('id' => '3', 'username' => 'guest', 'realm' => 0, 'enabled' => '', 'locked' => '', 'password' => ''),
		),
		'session' => $session + array(
			'sess_user_id'         => '42',
			'sess_user_credential' => hash('sha256', 'hash'),
			'sess_user_epoch'      => '0',
		),
	);
}

function session_lifetime_idle() : int {
	return (int) ini_get('session.gc_maxlifetime');
}

test('a request records the time of the activity', function () {
	$before = time();
	$result = cacti_test_run_auth_entry_probe(session_lifetime_request(array('sess_last_activity' => time() - 60)));

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['session']['sess_last_activity'])->toBeGreaterThanOrEqual($before)
		->and($result['page_continued'])->toBeTrue();
});

test('a session from before the upgrade starts its idle clock and continues', function () {
	$result = cacti_test_run_auth_entry_probe(session_lifetime_request(array()));

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['session'])->toHaveKey('sess_last_activity')
		->and($result['page_continued'])->toBeTrue();
});

test('a session idle for longer than session.gc_maxlifetime ends', function () {
	$result = cacti_test_run_auth_entry_probe(session_lifetime_request(array('sess_last_activity' => time() - session_lifetime_idle() - 5)));

	expect($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['events'])->toContain('session_destroy')
		->and($result['events'])->toContain('login_page')
		->and($result['page_continued'])->toBeFalse();
});

test('a session used within session.gc_maxlifetime continues', function () {
	$result = cacti_test_run_auth_entry_probe(session_lifetime_request(array('sess_last_activity' => time() - session_lifetime_idle() + 30)));

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['events'])->not->toContain('session_destroy')
		->and($result['page_continued'])->toBeTrue();
});

test('an idle session with a remember-me cookie is restored from the cookie', function () {
	$result = cacti_test_run_auth_entry_probe(session_lifetime_request(array('sess_last_activity' => time() - session_lifetime_idle() - 5), array(
		'config' => array('auth_method' => 1, 'guest_user' => 'guest', 'auth_cache_enabled' => 'on'),
		'cache'  => array(array('user_id' => 42, 'token' => hash('sha512', 'valid-token'), 'hostname' => '192.0.2.10', 'age_days' => 2)),
		'cookie' => '42,0,valid-token',
	)));

	expect($result['session']['sess_user_id'] ?? null)->toBe('42')
		->and($result['events'])->toContain('session_destroy')
		->and($result['events'])->toContain('cookie_set')
		->and($result['page_continued'])->toBeTrue();
});

function session_lifetime_cookie(int $age_days) : array {
	return cacti_test_run_auth_entry_probe(array(
		'config' => array('auth_method' => 1, 'auth_cache_enabled' => 'on'),
		'users'  => array(array('id' => '42', 'username' => 'alice', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'hash')),
		'cache'  => array(array('user_id' => 42, 'token' => hash('sha512', 'valid-token'), 'hostname' => '192.0.2.10', 'age_days' => $age_days)),
		'cookie' => '42,0,valid-token',
		'call'   => array('type' => 'check_auth_cookie'),
	));
}

test('a remember-me row within the 30-day cookie lifetime is honoured', function () {
	$result = session_lifetime_cookie(29);

	expect($result['return'])->toBe('42')
		->and($result['events'])->toContain('cookie_set');
});

test('a remember-me row older than the 30-day cookie is not honoured', function () {
	$result = session_lifetime_cookie(31);

	expect($result['return'])->toBeFalse()
		->and($result['events'])->not->toContain('cookie_set')
		->and($result['executed'])->toBe(array());
});
