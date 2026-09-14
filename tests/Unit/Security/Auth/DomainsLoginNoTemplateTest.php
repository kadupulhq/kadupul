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
 * A Domains user who binds successfully but has no account, in a domain whose
 * User Template is "No User", returns no user and no error. auth_login.php then
 * applies the global User Template or the guest account, as 1.2.31 did.
 */

require_once dirname(__DIR__, 3) . '/Helpers/DomainsLoginProcessHarness.php';

function domains_no_template_release_source() : ?string {
	static $source = false;

	if ($source === false) {
		$output = shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 4)) . ' show release/1.2.31:lib/auth.php 2>/dev/null');
		$source = (is_string($output) && strpos($output, 'function domains_login_process(') !== false) ? $output : null;
	}

	return $source;
}

/**
 * @return array<string, array<string, mixed>>
 */
function domains_no_template_legitimate_scenarios() : array {
	return array(
		'existing account' => array(
			'username' => 'alice',
			'request'  => array('realm' => '1001', 'login_password' => 'correct-horse'),
			'user_row' => array('id' => 7, 'username' => 'alice', 'realm' => 1001, 'enabled' => 'on'),
		),
		'new account copied from the domain template' => array(
			'username'       => 'bob',
			'request'        => array('realm' => '1001', 'login_password' => 'x'),
			'template_user'  => 4,
			'template_row'   => array('id' => 4, 'username' => 'template'),
			'after_copy_row' => array('id' => 22, 'username' => 'bob', 'realm' => 1001),
		),
		'new account in a domain with No User' => array(
			'username'      => 'carol',
			'request'       => array('realm' => '1001', 'login_password' => 'x'),
			'template_user' => 0,
		),
		'second domain with No User' => array(
			'username'      => 'dave',
			'request'       => array('realm' => '1002', 'login_password' => 'x'),
			'domains'       => array(1, 2),
			'template_user' => 0,
		),
	);
}

test('a bound user in a domain with No User gets no error so the global template and guest checks run', function () {
	$result = cacti_test_run_domains_login_process_1_2(array(
		'username'      => 'carol',
		'request'       => array('realm' => '1001', 'login_password' => 'x'),
		'template_user' => 0,
	));

	expect($result['error'])->toBeFalse()
		->and($result['error_msg'])->toBe('')
		->and($result['user'])->toBe(array())
		->and($result['ldap_calls'])->toBe(2)
		->and($result['copy_calls'])->toBe(0);
});

test('a domain with No User still refuses a failed bind', function () {
	$result = cacti_test_run_domains_login_process_1_2(array(
		'request'       => array('realm' => '1001', 'login_password' => 'wrong'),
		'ldap_ok'       => false,
		'template_user' => 0,
	));

	expect($result['error'])->toBeTrue()
		->and($result['user'])->toBe(array())
		->and($result['lockout_calls'])->toBe(1);
});

test('a realm outside user_domains never reaches the template fallback', function () {
	foreach (array('2', '3', '999', '1500') as $realm) {
		$result = cacti_test_run_domains_login_process_1_2(array(
			'request'       => array('realm' => $realm, 'login_password' => 'anything'),
			'template_user' => 0,
		));

		expect($result['error'])->toBeTrue()
			->and($result['user'])->toBe(array())
			->and($result['ldap_calls'])->toBe(0);
	}
});

test('legitimate Domains logins end as they did in 1.2.31', function () {
	foreach (domains_no_template_legitimate_scenarios() as $name => $scenario) {
		$current = cacti_test_run_domains_login_process_1_2($scenario);
		$release = cacti_test_run_domains_login_process_1_2($scenario, domains_no_template_release_source());

		expect($current)->toBe($release, $name);
	}
})->skip(function () {
	return domains_no_template_release_source() === null;
}, 'release/1.2.31 is not available in this clone');
