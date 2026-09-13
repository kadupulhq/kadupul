<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Web Basic authentication reads PHP_AUTH_USER, REMOTE_USER and
 * REDIRECT_REMOTE_USER in 1.2.31 order. HTTP_* entries are request headers a
 * client can send, so those copies are never read.
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

test('PHP_AUTH_USER is accepted as the Web Basic user', function () {
	expect(basic_identity_username(array('PHP_AUTH_USER' => 'admin')))->toBe('admin');
});

test('PHP_AUTH_USER is read ahead of REMOTE_USER, in 1.2.31 order', function () {
	expect(basic_identity_username(array('REMOTE_USER' => 'alice', 'PHP_AUTH_USER' => 'admin')))->toBe('admin');
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
