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
 * The LDAP login page is shown to unauthenticated clients, so every directory
 * failure must produce the same message. The detail belongs in the log, and
 * lockout counting stays as it was: only a rejected password counts.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function ldap_login_run(array $scenario) : array {
	$root = dirname(__DIR__, 4);
	$body = cacti_test_function_source(file_get_contents($root . '/lib/auth.php'), 'ldap_login_process');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

$GLOBALS['logs']          = array();
$GLOBALS['lockout_calls'] = 0;

$error     = false;
$error_msg = '';

function get_nfilter_request_var($name, $default = '') {
	return $name == 'login_password' ? $GLOBALS['scenario']['password'] : $default;
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
	$GLOBALS['logs'][] = $string;
}

function auth_checkclear_lockout($username, $realm) {
}

function auth_process_lockout_check($username, $realm) {
	return false;
}

function auth_process_lockout($username, $realm) {
	$GLOBALS['lockout_calls']++;
}

function cacti_ldap_search_dn($username) {
	return $GLOBALS['scenario']['search'];
}

function cacti_ldap_auth($username, $password, $dn) {
	return $GLOBALS['scenario']['auth'];
}

function db_fetch_row_prepared($sql, $params = array()) {
	return array('id' => 9, 'username' => $params[0], 'realm' => $params[1]);
}

$GLOBALS['scenario'] = $scenario;

PHP;

	$source .= $body . "\n\n";
	$source .= '$user = ldap_login_process($scenario[\'username\']);' . "\n";
	$source .= 'print json_encode(array(\'error\' => $error, \'error_msg\' => $error_msg, \'user\' => $user, \'logs\' => $GLOBALS[\'logs\'], \'lockout_calls\' => $GLOBALS[\'lockout_calls\']));' . "\n";

	return cacti_test_run_php_source($source, $scenario + array(
		'username' => 'alice',
		'password' => 'secret',
		'search'   => array('error_num' => '0', 'error_text' => '', 'dn' => 'uid=alice,dc=example,dc=com'),
		'auth'     => array('error_num' => '0', 'error_text' => ''),
	));
}

function ldap_login_failures() : array {
	return array(
		'user not found'      => array('search' => array('error_num' => 14, 'error_text' => 'Unable to find users DN', 'dn' => '')),
		'several users found' => array('search' => array('error_num' => 13, 'error_text' => 'More than one matching user found', 'dn' => '')),
		'wrong password'      => array('auth' => array('error_num' => 1, 'error_text' => 'Authentication Failure')),
		'not in group'        => array('auth' => array('error_num' => 8, 'error_text' => 'Insufficient Access to Server (ldap.example.com)')),
		'group not found'     => array('auth' => array('error_num' => 12, 'error_text' => 'Group DN could not be found to compare on Server (ldap.example.com)')),
		'server unreachable'  => array('auth' => array('error_num' => 9, 'error_text' => 'Unable to Connect to Server (ldap.example.com)')),
	);
}

test('every LDAP login failure shows the same generic message', function () {
	foreach (ldap_login_failures() as $case => $scenario) {
		$result = ldap_login_run($scenario);

		expect($result['error'])->toBeTrue($case)
			->and($result['error_msg'])->toBe('Access Denied!  Login Failed.')
			->and($result['user'])->toBe(array());
	}
});

test('the LDAP failure detail is still logged', function () {
	foreach (ldap_login_failures() as $case => $scenario) {
		$result = ldap_login_run($scenario);
		$detail = ($scenario['auth'] ?? $scenario['search'])['error_text'];

		expect(implode("\n", $result['logs']))->toContain($detail);
	}
});

test('only a rejected password counts toward lockout, as before', function () {
	$expected = array(
		'user not found'      => 0,
		'several users found' => 0,
		'wrong password'      => 1,
		'not in group'        => 0,
		'group not found'     => 0,
		'server unreachable'  => 0,
	);

	foreach (ldap_login_failures() as $case => $scenario) {
		expect(ldap_login_run($scenario)['lockout_calls'])->toBe($expected[$case]);
	}
});

test('an empty password keeps its own message and still counts toward lockout', function () {
	$result = ldap_login_run(array('password' => ''));

	expect($result['error'])->toBeTrue()
		->and($result['error_msg'])->toBe('Access Denied!  No password provided by user.')
		->and($result['lockout_calls'])->toBe(1);
});

test('a successful LDAP login still returns the account without an error', function () {
	$result = ldap_login_run(array());

	expect($result['error'])->toBeFalse()
		->and($result['error_msg'])->toBe('')
		->and($result['user'])->toBe(array('id' => 9, 'username' => 'alice', 'realm' => 3))
		->and($result['lockout_calls'])->toBe(0);
});
