<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * An install still set to the retired "no authentication" method is moved to
 * local authentication. The request that triggers the move must not receive a
 * session; the administrator signs in normally afterwards.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function no_auth_executed(array $result, string $needle) : array {
	return array_values(array_filter($result['executed'], function (array $row) use ($needle) : bool {
		return strpos($row['sql'], $needle) !== false;
	}));
}

test('moving off no authentication does not grant the administrator session', function () {
	$result = cacti_test_run_auth_entry_probe(array(
		'config' => array('auth_method' => 0, 'admin_user' => 5),
		'users'  => array(array('id' => 5, 'username' => 'admin', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'stored-hash')),
	));

	expect($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['session'])->not->toHaveKey('sess_change_password')
		->and($result['config_writes'])->toContain(array('auth_method', 1))
		->and($result['page_continued'])->toBeFalse();
});

test('the administrator password is kept and a change is required at next login', function () {
	$result = cacti_test_run_auth_entry_probe(array(
		'config' => array('auth_method' => 0, 'admin_user' => 5),
		'users'  => array(array('id' => 5, 'username' => 'admin', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'stored-hash')),
	));

	$updates = no_auth_executed($result, 'UPDATE user_auth');

	expect(no_auth_executed($result, "password = ''"))->toBe(array())
		->and($updates)->toHaveCount(1)
		->and($updates[0]['sql'])->toContain("must_change_password = 'on'")
		->and($updates[0]['params'])->toBe(array(5));
});

test('without the configured administrator an enabled settings user is chosen', function () {
	$result = cacti_test_run_auth_entry_probe(array(
		'config' => array('auth_method' => 0, 'admin_user' => 99),
		'users'  => array(
			array('id' => 3, 'username' => 'olduser', 'realm' => 0, 'enabled' => '', 'locked' => '', 'password' => 'stored-hash'),
			array('id' => 7, 'username' => 'ops', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'stored-hash'),
		),
		'realms' => array(array(3, 15), array(7, 15)),
	));

	$updates = no_auth_executed($result, 'UPDATE user_auth');

	expect($updates)->toHaveCount(1)
		->and(array_map('strval', $updates[0]['params']))->toBe(array('7'))
		->and($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['config_writes'])->toContain(array('auth_method', 1));
});

test('a disabled configured administrator is passed over for an enabled settings user', function () {
	$result = cacti_test_run_auth_entry_probe(array(
		'config' => array('auth_method' => 0, 'admin_user' => 5),
		'users'  => array(
			array('id' => 5, 'username' => 'admin', 'realm' => 0, 'enabled' => '', 'locked' => '', 'password' => 'stored-hash'),
			array('id' => 7, 'username' => 'ops', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'stored-hash'),
		),
		'realms' => array(array(5, 15), array(7, 15)),
	));

	$updates = no_auth_executed($result, 'UPDATE user_auth');

	expect($updates)->toHaveCount(1)
		->and(array_map('strval', $updates[0]['params']))->toBe(array('7'))
		->and($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['session'])->not->toHaveKey('sess_change_password')
		->and($result['events'])->not->toContain('cookie_set')
		->and($result['config_writes'])->toContain(array('auth_method', 1))
		->and($result['page_continued'])->toBeFalse();
});

test('without the configured administrator a settings user from an enabled group is chosen', function () {
	$result = cacti_test_run_auth_entry_probe(array(
		'config'        => array('auth_method' => 0, 'admin_user' => 99),
		'users'         => array(array('id' => 9, 'username' => 'grp', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => 'stored-hash')),
		'realms'        => array(),
		'groups'        => array(array(4, 'on')),
		'group_members' => array(array(4, 9)),
		'group_realms'  => array(array(4, 15)),
	));

	$updates = no_auth_executed($result, 'UPDATE user_auth');

	expect($updates)->toHaveCount(1)
		->and(array_map('strval', $updates[0]['params']))->toBe(array('9'));
});

test('without an administrator account the install still leaves no authentication', function () {
	$result = cacti_test_run_auth_entry_probe(array(
		'config' => array('auth_method' => 0, 'admin_user' => 5),
		'users'  => array(),
	));

	expect($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['config_writes'])->toContain(array('auth_method', 1))
		->and(no_auth_executed($result, 'UPDATE user_auth'))->toBe(array())
		->and($result['page_continued'])->toBeFalse();
});
