<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
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
