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
 * An unknown LDAP user fails at the DN search, before any bind, so without a
 * floor it answers sooner than a wrong password and the login page tells an
 * attacker which names exist. Every failed LDAP or Domains login is held to
 * one second after it started, as Zabbix does in equalizeUserVerificationTime.
 * A successful login does not wait.
 */

require_once dirname(__DIR__, 3) . '/Helpers/LdapDirectoryProbe.php';

/** @return array<int, string> */
function ldap_timing_functions(array $names) : array {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');

	/* run against a tree without the helper too, so the floor is what the test measures */
	if (strpos($source, "\nfunction auth_ldap_equalize_failure(") !== false) {
		$names[] = 'auth_ldap_equalize_failure';
	}

	return $names;
}

/**
 * @return array<string, mixed>
 */
function ldap_timing_login(string $username, string $password) : array {
	$scenario = ldap_directory_probe_replicas(array('ldap1'));

	$scenario['request'] = array('login_password' => $password);
	$scenario['call']    = 'ldap_login_process(' . var_export($username, true) . ')';

	return ldap_directory_probe_run($scenario, ldap_timing_functions(array('ldap_login_process')));
}

/**
 * @return array<string, mixed>
 */
function ldap_timing_domain_login(string $username, string $password) : array {
	$scenario = ldap_directory_probe_replicas(array('ldap1'));

	$scenario['request'] = array('login_password' => $password, 'realm' => '1001');
	$scenario['domain']  = array(
		'domain_id'         => 1,
		'server'            => 'ldap1',
		'port'              => '389',
		'port_ssl'          => '636',
		'proto_version'     => '3',
		'encryption'        => '0',
		'referrals'         => '0',
		'mode'              => '2',
		'dn'                => 'uid=<username>,ou=people,dc=example,dc=com',
		'group_require'     => '',
		'group_dn'          => '',
		'group_attrib'      => '',
		'group_member_type' => '1',
		'search_base'       => 'dc=example,dc=com',
		'search_filter'     => '(uid=<username>)',
		'specific_dn'       => 'cn=search,dc=example,dc=com',
		'specific_password' => 'search-secret',
	);
	$scenario['call'] = 'domains_login_process(' . var_export($username, true) . ')';

	return ldap_directory_probe_run($scenario, ldap_timing_functions(array('domains_login_process', 'domains_ldap_servers', 'domains_ldap_auth', 'domains_ldap_search_dn')));
}

test('an unknown LDAP user and a wrong password both take at least one second', function () {
	$unknown = ldap_timing_login('mallory', 'guess');
	$wrong   = ldap_timing_login('alice', 'guess');

	expect($unknown['error'])->toBeTrue()
		->and($wrong['error'])->toBeTrue()
		->and($unknown['calls']['binds'])->toBe(array(array('ldap1', 'cn=search,dc=example,dc=com')))
		->and($unknown['elapsed'])->toBeGreaterThanOrEqual(1.0)
		->and($wrong['elapsed'])->toBeGreaterThanOrEqual(1.0)
		->and(abs($unknown['elapsed'] - $wrong['elapsed']))->toBeLessThan(0.25);
});

test('an empty password and an empty username are held as well', function () {
	expect(ldap_timing_login('alice', '')['elapsed'])->toBeGreaterThanOrEqual(1.0)
		->and(ldap_timing_login('', 'guess')['elapsed'])->toBeGreaterThanOrEqual(1.0);
});

test('an unknown Domains user and a wrong password both take at least one second', function () {
	$unknown = ldap_timing_domain_login('mallory', 'guess');
	$wrong   = ldap_timing_domain_login('alice', 'guess');

	expect($unknown['error'])->toBeTrue()
		->and($wrong['error'])->toBeTrue()
		->and($unknown['elapsed'])->toBeGreaterThanOrEqual(1.0)
		->and($wrong['elapsed'])->toBeGreaterThanOrEqual(1.0);
});

test('a successful LDAP or Domains login does not wait', function () {
	$ldap   = ldap_timing_login('alice', 'secret');
	$domain = ldap_timing_domain_login('alice', 'secret');

	expect($ldap['error'])->toBeFalse()
		->and($ldap['result']['id'])->toBe(9)
		->and($ldap['elapsed'])->toBeLessThan(0.5)
		->and($domain['error'])->toBeFalse()
		->and($domain['elapsed'])->toBeLessThan(0.5);
});
