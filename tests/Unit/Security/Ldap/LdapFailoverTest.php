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
 * With several servers configured, a login moves to the next server only when
 * the current one cannot be reached. A rejected password is the same answer on
 * every replica, and repeating it counts once per server toward the directory
 * lockout. The user is bound on the server whose search found the DN.
 */

require_once dirname(__DIR__, 3) . '/Helpers/LdapDirectoryProbe.php';

/**
 * @return array<string, mixed>
 */
function ldap_failover_run(array $scenario, string $call, array $auth_functions = array()) : array {
	$scenario['call'] = $call;

	return ldap_directory_probe_run($scenario, $auth_functions);
}

/**
 * @return array<string, mixed>
 */
function ldap_failover_login(array $scenario, string $password) : array {
	$scenario['request'] = array('login_password' => $password);

	return ldap_failover_run($scenario, "ldap_login_process('alice')", array('ldap_login_process'));
}

/**
 * @return array<string, mixed>
 */
function ldap_failover_domain_login(array $scenario, string $password) : array {
	$scenario['request'] = array('login_password' => $password, 'realm' => '1001');
	$scenario['domain']  = array(
		'domain_id'         => 1,
		'server'            => $scenario['config']['ldap_server'],
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

	$scenario['config']['ldap_server'] = 'global.example.com';

	return ldap_failover_run($scenario, "domains_login_process('alice')", array('domains_login_process', 'domains_ldap_servers', 'domains_ldap_auth', 'domains_ldap_search_dn'));
}

/** @return array<int, string> */
function ldap_failover_user_binds(array $result) : array {
	$hosts = array();

	foreach ($result['calls']['binds'] as $bind) {
		if ($bind[1] === 'uid=alice,ou=people,dc=example,dc=com') {
			$hosts[] = $bind[0];
		}
	}

	return $hosts;
}

test('a rejected password is not tried against the next server', function () {
	$result = ldap_failover_run(ldap_directory_probe_replicas(), "cacti_ldap_auth('alice', 'wrong', 'uid=alice,ou=people,dc=example,dc=com')");

	expect($result['result']['error_num'])->toBe(1)
		->and(ldap_failover_user_binds($result))->toBe(array('ldap1'));
});

test('an unreachable server still fails over to the next one', function () {
	$scenario = ldap_directory_probe_replicas();
	$scenario['servers']['ldap1']['down'] = true;

	$result = ldap_failover_run($scenario, "cacti_ldap_auth('alice', 'secret', 'uid=alice,ou=people,dc=example,dc=com')");

	expect($result['result']['error_num'])->toBe(0)
		->and(ldap_failover_user_binds($result))->toBe(array('ldap1', 'ldap2'));
});

test('a failed StartTLS fails over to the next server', function () {
	$scenario = ldap_directory_probe_replicas();
	$scenario['config']['ldap_encryption']     = '2';
	$scenario['servers']['ldap1']['tls_fails'] = true;

	$result = ldap_failover_run($scenario, "cacti_ldap_auth('alice', 'secret', 'uid=alice,ou=people,dc=example,dc=com')");

	expect($result['result']['error_num'])->toBe(0)
		->and(ldap_failover_user_binds($result))->toBe(array('ldap2'));
});

test('a wrong password at the login page binds once and counts once toward lockout', function () {
	$result = ldap_failover_login(ldap_directory_probe_replicas(), 'wrong');

	expect($result['error'])->toBeTrue()
		->and(ldap_failover_user_binds($result))->toBe(array('ldap1'))
		->and($result['lockouts'])->toBe(1);
});

test('the user is bound on the server whose search found the DN', function () {
	$scenario = ldap_directory_probe_replicas(array('ldap1', 'ldap2', 'ldap3'));
	$scenario['servers']['ldap1']['down'] = true;

	$result   = ldap_failover_login($scenario, 'secret');
	$searched = array_column($result['calls']['searches'], 0);

	expect($result['error'])->toBeFalse()
		->and($result['result']['id'])->toBe(9)
		->and($searched)->toBe(array('ldap2'))
		->and(ldap_failover_user_binds($result))->toBe(array('ldap2'));
});

test('a server that loses the connection between search and bind moves both to the next server', function () {
	$scenario = ldap_directory_probe_replicas();

	/* ldap1 answers the search, then reports itself unreachable on the user bind */
	$scenario['servers']['ldap1']['entries'][0]['bind_errno'] = -1;

	$result   = ldap_failover_login($scenario, 'secret');
	$searched = array_column($result['calls']['searches'], 0);

	expect($result['error'])->toBeFalse()
		->and($searched)->toBe(array('ldap1', 'ldap2'))
		->and(ldap_failover_user_binds($result))->toBe(array('ldap1', 'ldap2'));
});

test('a user missing from the first server is searched for and bound on the next', function () {
	$scenario = ldap_directory_probe_replicas();
	array_shift($scenario['servers']['ldap1']['entries']);

	$result = ldap_failover_login($scenario, 'secret');

	expect($result['error'])->toBeFalse()
		->and(array_column($result['calls']['searches'], 0))->toBe(array('ldap1', 'ldap2'))
		->and(ldap_failover_user_binds($result))->toBe(array('ldap2'));
});

test('a Domains login follows the same rules', function () {
	$wrong = ldap_failover_domain_login(ldap_directory_probe_replicas(), 'wrong');

	expect($wrong['error'])->toBeTrue()
		->and(ldap_failover_user_binds($wrong))->toBe(array('ldap1'))
		->and($wrong['lockouts'])->toBe(1);

	$scenario = ldap_directory_probe_replicas();
	$scenario['servers']['ldap1']['down'] = true;

	$down = ldap_failover_domain_login($scenario, 'secret');

	expect($down['error'])->toBeFalse()
		->and(array_column($down['calls']['searches'], 0))->toBe(array('ldap2'))
		->and(ldap_failover_user_binds($down))->toBe(array('ldap2'))
		->and($down['calls']['connects'])->not->toContain('global.example.com');
});
