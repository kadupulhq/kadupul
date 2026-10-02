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
 * Selective DEBUG on the login page turns on libldap's own debug output, which
 * goes to the web server error log. The level must leave out LDAP_DEBUG_PACKETS
 * (0x02), the packet level, because a simple bind packet carries the password
 * in clear. Kadupul's own AUTH log lines must not carry a password either.
 */

require_once dirname(__DIR__, 3) . '/Helpers/LdapDirectoryProbe.php';

/**
 * @return array<string, mixed>
 */
function ldap_debug_level_run(string $call) : array {
	$scenario = ldap_directory_probe_replicas(array('ldap1'));

	$scenario['selective_log_level']          = 5;
	$scenario['config']['ldap_debug']         = 'on';
	$scenario['config']['ldap_group_require'] = 'on';
	$scenario['config']['ldap_group_dn']      = 'cn=ops,dc=example,dc=com';
	$scenario['config']['ldap_group_attrib']  = 'member';
	$scenario['config']['ldap_group_member_type'] = '1';
	$scenario['servers']['ldap1']['groups']   = array('cn=ops,dc=example,dc=com' => array('member' => array('uid=alice,ou=people,dc=example,dc=com')));
	$scenario['call'] = $call;

	return ldap_directory_probe_run($scenario);
}

test('Selective DEBUG asks libldap for trace output without packet dumps', function () {
	$result = ldap_debug_level_run("cacti_ldap_auth('alice', 'secret', 'uid=alice,ou=people,dc=example,dc=com')");

	expect($result['result']['error_num'])->toBe(0)
		->and($result['calls']['debug_levels'])->not->toBe(array());

	foreach ($result['calls']['debug_levels'] as $level) {
		expect($level & 0x02)->toBe(0)
			->and($level & 0x01)->toBe(0x01);
	}
});

test('LDAP log lines at DEBUG carry neither the user nor the search password', function () {
	$calls = array(
		"cacti_ldap_search_dn('alice')",
		"cacti_ldap_auth('alice', 'secret', 'uid=alice,ou=people,dc=example,dc=com')",
		"cacti_ldap_auth('alice', 'wrong-password', 'uid=alice,ou=people,dc=example,dc=com')",
		"cacti_ldap_search_cn('alice', array('cn', 'mail'))",
	);

	foreach ($calls as $call) {
		$result = ldap_debug_level_run($call);
		$logs   = implode("\n", $result['logs']);

		expect($result['logs'])->not->toBe(array())
			->and($logs)->not->toContain('secret')
			->and($logs)->not->toContain('wrong-password');
	}
});
