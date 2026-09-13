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
 * Runs Ldap::Authenticate() from lib/ldap.php in a child process against a
 * small in-memory directory. php-ldap is compiled in, so its functions cannot
 * be redefined globally; the class is evaluated inside a namespace whose
 * ldap_* functions record each bind, search filter and compare.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function ldap_group_probe_source() : string {
	return <<<'PHP'
<?php
namespace {
	define('POLLER_VERBOSITY_NONE', 1);
	define('POLLER_VERBOSITY_LOW', 2);
	define('POLLER_VERBOSITY_MEDIUM', 3);
	define('POLLER_VERBOSITY_HIGH', 4);
	define('POLLER_VERBOSITY_DEBUG', 5);
	define('POLLER_VERBOSITY_DEVDBG', 6);

	$GLOBALS['scenario'] = json_decode(stream_get_contents(STDIN), true);
	$GLOBALS['calls']    = array('binds' => array(), 'searches' => array(), 'compares' => array());

	function read_config_option($name, $force = false) {
		return $GLOBALS['scenario']['config'][$name] ?? '';
	}

	function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
	}

	function get_selective_log_level() {
		return POLLER_VERBOSITY_NONE;
	}

	function cacti_debug_backtrace($entry = '', $html = false, $record = true, $limit = 0, $skip = 0) {
		return '';
	}

	function __($text, ...$args) {
		return vsprintf($text, $args);
	}

	function cacti_sizeof($array) {
		return is_array($array) ? count($array) : 0;
	}

	function cacti_session_close() {
	}

	function cacti_session_start($regenerate = false) {
	}

	function CactiErrorHandler($level, $message, $file, $line) {
		return true;
	}
}

namespace LdapGroupProbe {
	function ldap_connect($host = null, $port = 389) {
		return 'probe-connection';
	}

	function ldap_set_option($conn, $option, $value) {
		return true;
	}

	function ldap_start_tls($conn) {
		return true;
	}

	function ldap_close($conn) {
		return true;
	}

	function ldap_errno($conn) {
		return 0x31;
	}

	function ldap_error($conn) {
		return 'Invalid credentials';
	}

	function ldap_bind($conn, $dn = null, $password = null) {
		$GLOBALS['calls']['binds'][] = $dn;

		foreach ($GLOBALS['scenario']['directory'] as $entry) {
			if (($entry['bind'] === $dn || !empty($GLOBALS['scenario']['bind_any'])) && $entry['password'] === $password) {
				return true;
			}
		}

		return false;
	}

	/* evaluates the (|(uid=x)(cn=x)(userPrincipalName=x)) filter the group check builds */
	function ldap_search($conn, $base, $filter, $attributes = array()) {
		$GLOBALS['calls']['searches'][] = $filter;

		preg_match_all('/\((uid|cn|userPrincipalName)=((?:[^()\\\\]|\\\\[0-9a-fA-F]{2})*)\)/', $filter, $assertions, PREG_SET_ORDER);

		$hits = array();

		foreach ($GLOBALS['scenario']['directory'] as $entry) {
			foreach ($assertions as $assertion) {
				$value = preg_replace_callback('/\\\\([0-9a-fA-F]{2})/', function (array $hex) : string {
					return chr(hexdec($hex[1]));
				}, $assertion[2]);

				if (isset($entry[$assertion[1]]) && strcasecmp($entry[$assertion[1]], $value) === 0) {
					$hits[] = $entry;

					break;
				}
			}
		}

		return $hits;
	}

	function ldap_first_entry($conn, $result) {
		return $result[0] ?? false;
	}

	function ldap_get_dn($conn, $entry) {
		return $entry['dn'];
	}

	function ldap_compare($conn, $dn, $attribute, $value) {
		$GLOBALS['calls']['compares'][] = array($dn, $attribute, $value);

		if (!isset($GLOBALS['scenario']['groups'][$dn][$attribute])) {
			return -1;
		}

		return in_array($value, $GLOBALS['scenario']['groups'][$dn][$attribute], true);
	}
}

namespace {
	// nosemgrep: php.lang.security.eval-use.eval-use -- test-only evaluation of a repository-owned file
	eval('namespace LdapGroupProbe; ' . preg_replace('/^<\?php\s*/', '', $GLOBALS['scenario']['source']));

	$ldap = new \LdapGroupProbe\Ldap();
	$ldap->username = $GLOBALS['scenario']['username'];
	$ldap->password = $GLOBALS['scenario']['password'];

	$output = $ldap->Authenticate();

	print json_encode(array(
		'error_num' => (int) $output['error_num'],
		'binds'     => $GLOBALS['calls']['binds'],
		'searches'  => $GLOBALS['calls']['searches'],
		'compares'  => $GLOBALS['calls']['compares'],
	));
}
PHP;
}

function ldap_group_release_source() : ?string {
	static $source = false;

	if ($source === false) {
		$output = shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 4)) . ' show release/1.2.31:lib/ldap.php 2>/dev/null');
		$source = (is_string($output) && strpos($output, 'function Authenticate()') !== false) ? $output : null;
	}

	return $source;
}

/**
 * @param array<string, mixed> $scenario
 *
 * @return array<string, mixed>
 */
function ldap_group_run(array $scenario, ?string $source = null) : array {
	$scenario['source'] = $source ?? file_get_contents(dirname(__DIR__, 4) . '/lib/ldap.php');

	return cacti_test_run_php_source(ldap_group_probe_source(), $scenario);
}

/* posixGroup: members are listed by login name in memberUid, and users bind with a full DN template */
function ldap_group_posix_scenario(array $members = array('alice'), string $username = 'alice', string $password = 'secret') : array {
	return array(
		'username'  => $username,
		'password'  => $password,
		'config'    => array(
			'ldap_dn'                => 'uid=<username>,ou=people,dc=example,dc=com',
			'ldap_server'            => 'ldap.example.com',
			'ldap_port'              => '389',
			'ldap_version'           => '3',
			'ldap_encryption'        => '0',
			'ldap_referrals'         => '0',
			'ldap_group_require'     => 'on',
			'ldap_group_dn'          => 'cn=ops,ou=groups,dc=example,dc=com',
			'ldap_group_attrib'      => 'memberUid',
			'ldap_group_member_type' => '2',
			'ldap_search_base'       => 'dc=example,dc=com',
		),
		'directory' => array(
			array(
				'dn'       => 'uid=alice,ou=people,dc=example,dc=com',
				'bind'     => 'uid=alice,ou=people,dc=example,dc=com',
				'password' => 'secret',
				'uid'      => 'alice',
				'cn'       => 'Alice Smith',
			),
		),
		'groups'    => array(
			'cn=ops,ou=groups,dc=example,dc=com' => array('memberUid' => $members),
		),
	);
}

/* Active Directory style: users bind as their UPN, and the group lists member DNs */
function ldap_group_upn_scenario() : array {
	$scenario = ldap_group_posix_scenario();

	$scenario['config']['ldap_dn']           = '<username>@example.com';
	$scenario['config']['ldap_group_attrib'] = 'member';
	$scenario['directory'] = array(
		array(
			'dn'                => 'CN=Alice Smith,OU=Staff,DC=example,DC=com',
			'bind'              => 'alice@example.com',
			'password'          => 'secret',
			'cn'                => 'Alice Smith',
			'userPrincipalName' => 'alice@example.com',
		),
	);
	$scenario['groups'] = array(
		'cn=ops,ou=groups,dc=example,dc=com' => array('member' => array('CN=Alice Smith,OU=Staff,DC=example,DC=com')),
	);

	return $scenario;
}

function ldap_group_expected_filter(string $dn) : string {
	$value = ldap_escape($dn, '', LDAP_ESCAPE_FILTER);

	return '(|(uid=' . $value . ')(cn=' . $value . ')(userPrincipalName=' . $value . '))';
}

test('a posixGroup memberUid member authenticates with a full DN bind template', function () {
	$result = ldap_group_run(ldap_group_posix_scenario());

	expect($result['searches'])->toBe(array(ldap_group_expected_filter('uid=alice,ou=people,dc=example,dc=com')))
		->and($result['compares'])->toBe(array(array('cn=ops,ou=groups,dc=example,dc=com', 'memberUid', 'alice')))
		->and($result['error_num'])->toBe(0);
});

test('a UPN bind template finds the true DN and compares it with the group members', function () {
	$result = ldap_group_run(ldap_group_upn_scenario());

	expect($result['searches'])->toBe(array(ldap_group_expected_filter('alice@example.com')))
		->and($result['compares'])->toBe(array(array('cn=ops,ou=groups,dc=example,dc=com', 'member', 'CN=Alice Smith,OU=Staff,DC=example,DC=com')))
		->and($result['error_num'])->toBe(0);
});

test('a user outside the group is refused', function () {
	$result = ldap_group_run(ldap_group_posix_scenario(array('bob')));

	expect($result['error_num'])->toBe(8);
});

test('a wrong password is refused before any group lookup', function () {
	$result = ldap_group_run(ldap_group_posix_scenario(array('alice'), 'alice', 'wrong'));

	expect($result['error_num'])->toBe(1)
		->and($result['searches'])->toBe(array())
		->and($result['compares'])->toBe(array());
});

test('filter metacharacters in the login name stay escaped in the group lookup', function () {
	$username = 'alice)(uid=*';
	$scenario = ldap_group_posix_scenario(array('alice'), $username);

	$scenario['bind_any'] = true;

	$result = ldap_group_run($scenario);
	$dn     = 'uid=' . ldap_escape($username, '', LDAP_ESCAPE_DN) . ',ou=people,dc=example,dc=com';

	expect($result['searches'])->toBe(array(ldap_group_expected_filter($dn)))
		->and($result['searches'][0])->not->toContain('(uid=*')
		->and($result['error_num'])->toBe(8);
});

test('a different entry sharing the login name does not satisfy the group check', function () {
	$scenario = ldap_group_posix_scenario(array(), 'bob');

	$scenario['config']['ldap_group_attrib'] = 'member';
	$scenario['directory'] = array(
		array('dn' => 'cn=bob,ou=admins,dc=example,dc=com', 'bind' => 'cn=bob,ou=admins,dc=example,dc=com', 'password' => 'other', 'cn' => 'bob'),
		array('dn' => 'uid=bob,ou=people,dc=example,dc=com', 'bind' => 'uid=bob,ou=people,dc=example,dc=com', 'password' => 'secret', 'uid' => 'bob', 'cn' => 'Bob Jones'),
	);
	$scenario['groups'] = array('cn=ops,ou=groups,dc=example,dc=com' => array('member' => array('cn=bob,ou=admins,dc=example,dc=com')));

	$result = ldap_group_run($scenario);

	expect($result['compares'])->toBe(array(array('cn=ops,ou=groups,dc=example,dc=com', 'member', 'bob')))
		->and($result['error_num'])->toBe(8);
});

test('group checks end as they did in 1.2.31', function () {
	$distinguished = ldap_group_posix_scenario();

	$distinguished['config']['ldap_group_attrib']      = 'member';
	$distinguished['config']['ldap_group_member_type'] = '1';
	$distinguished['groups'] = array(
		'cn=ops,ou=groups,dc=example,dc=com' => array('member' => array('uid=alice,ou=people,dc=example,dc=com')),
	);

	$escaped = ldap_group_posix_scenario(array('alice'), 'alice)(uid=*');
	$escaped['bind_any'] = true;

	$scenarios = array(
		'posixGroup memberUid'        => ldap_group_posix_scenario(),
		'UPN template with member DNs' => ldap_group_upn_scenario(),
		'Distinguished Name type'     => $distinguished,
		'not a member'                => ldap_group_posix_scenario(array('bob')),
		'wrong password'              => ldap_group_posix_scenario(array('alice'), 'alice', 'wrong'),
		'escaped login name'          => $escaped,
	);

	foreach ($scenarios as $name => $scenario) {
		expect(ldap_group_run($scenario))->toBe(ldap_group_run($scenario, ldap_group_release_source()), $name);
	}
})->skip(function () {
	return ldap_group_release_source() === null;
}, 'release/1.2.31 is not available in this clone');
