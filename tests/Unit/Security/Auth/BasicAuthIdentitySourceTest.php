<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Web Basic authentication must take the user name only from variables the
 * web server sets after it has authenticated the request. HTTP_* entries are
 * request headers, and PHP fills PHP_AUTH_USER from the Authorization header
 * whether or not the server checked it.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function basic_identity_username(array $server, int $auth_method = 2) : mixed {
	return cacti_test_run_auth_entry_probe(array(
		'config' => array('auth_method' => $auth_method),
		'server' => $server,
		'call'   => array('type' => 'get_basic_auth_username'),
	))['return'];
}

test('request header variants are not accepted as the Web Basic user', function () {
	foreach (array('HTTP_REMOTE_USER', 'HTTP_PHP_AUTH_USER', 'HTTP_REDIRECT_REMOTE_USER') as $key) {
		expect(basic_identity_username(array($key => 'admin')))->toBeFalse();
	}
});

test('PHP_AUTH_USER without a server-set user is not accepted', function () {
	expect(basic_identity_username(array('PHP_AUTH_USER' => 'admin')))->toBeFalse();
});

test('the server-set REMOTE_USER wins over PHP_AUTH_USER', function () {
	expect(basic_identity_username(array('REMOTE_USER' => 'alice', 'PHP_AUTH_USER' => 'admin')))->toBe('alice');
});

test('server-set user variables are still accepted', function () {
	expect(basic_identity_username(array('REMOTE_USER' => 'alice')))->toBe('alice')
		->and(basic_identity_username(array('REDIRECT_REMOTE_USER' => 'alice')))->toBe('alice')
		->and(basic_identity_username(array('REMOTE_USER' => 'alice@EXAMPLE.COM')))->toBe('alice');
});

test('no user is returned when Web Basic authentication is not configured', function () {
	expect(basic_identity_username(array('REMOTE_USER' => 'alice'), 1))->toBeFalse();
});

test('a Remote-User request header does not log in an existing Web Basic account', function () {
	$result = cacti_test_run_auth_entry_probe(array(
		'config' => array('auth_method' => 2),
		'users'  => array(array('id' => 1, 'username' => 'admin', 'realm' => 2, 'enabled' => 'on', 'locked' => '')),
		'server' => array('HTTP_REMOTE_USER' => 'admin'),
	));

	expect($result['session'])->not->toHaveKey('sess_user_id')
		->and($result['events'])->toContain('login_page');
});
