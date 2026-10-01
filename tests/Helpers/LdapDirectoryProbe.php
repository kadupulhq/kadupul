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
 * Runs lib/ldap.php, and the named lib/auth.php login functions, in a child
 * process against in-memory directory servers. php-ldap is compiled in, so its
 * functions cannot be redefined globally; both files are evaluated inside a
 * namespace whose ldap_* functions answer from the scenario and record every
 * connect, bind, search and debug option.
 *
 * A scenario names its servers. Each server may be 'down' (every operation
 * fails as libldap reports an unreachable server, -1), may refuse StartTLS,
 * and holds its own entries. An entry has a dn, an optional bind password and
 * any attributes a search filter compares; 'bind_errno' makes every bind as
 * that entry fail with the given libldap error.
 */

require_once __DIR__ . '/AuthEntryProbe.php';

function ldap_directory_probe_source() : string {
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
	$GLOBALS['calls']    = array('connects' => array(), 'binds' => array(), 'searches' => array(), 'debug_levels' => array());
	$GLOBALS['logs']     = array();
	$GLOBALS['lockouts'] = 0;
	$GLOBALS['links']    = array();

	$error     = false;
	$error_msg = '';

	function read_config_option($name, $force = false) {
		return $GLOBALS['scenario']['config'][$name] ?? '';
	}

	function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
		$GLOBALS['logs'][] = $string;
	}

	function get_selective_log_level() {
		return $GLOBALS['scenario']['selective_log_level'] ?? POLLER_VERBOSITY_NONE;
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

	function get_nfilter_request_var($name, $default = '') {
		return $GLOBALS['scenario']['request'][$name] ?? $default;
	}

	function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array()) {
		return $GLOBALS['scenario']['request'][$name] ?? '';
	}

	function get_client_addr() {
		return '203.0.113.9';
	}

	function get_auth_realms($login = false) {
		return array('0' => array('name' => 'Local'), '1001' => array('name' => 'Example'));
	}

	function auth_checkclear_lockout($username, $realm) {
	}

	function auth_process_lockout_check($username, $realm) {
		return false;
	}

	function auth_process_lockout($username, $realm) {
		$GLOBALS['lockouts']++;
	}

	function db_fetch_row_prepared($sql, $params = array()) {
		if (strpos($sql, 'user_domains_ldap') !== false) {
			return $GLOBALS['scenario']['domain'] ?? array();
		}

		return array('id' => 9, 'username' => $params[0], 'realm' => $params[1] ?? 0);
	}

	function db_fetch_cell_prepared($sql, $params = array()) {
		if (strpos($sql, 'SELECT server') !== false) {
			return $GLOBALS['scenario']['domain']['server'] ?? '';
		}

		if (strpos($sql, 'domain_name') !== false) {
			return 'Example';
		}

		return 0;
	}
}

namespace LdapDirectoryProbe {
	function probe_server($link) {
		$host = $GLOBALS['links'][$link] ?? '';

		return $GLOBALS['scenario']['servers'][$host] ?? array('down' => true);
	}

	function probe_fail($link, $errno) {
		$GLOBALS['links'][$link . '#errno'] = $errno;

		return false;
	}

	function ldap_connect($host = null, $port = 389) {
		$name = preg_replace('#^ldaps?://#', '', (string) $host);
		$name = preg_replace('#:\d+$#', '', $name);
		$link = 'link' . count($GLOBALS['calls']['connects']);

		$GLOBALS['calls']['connects'][] = $name;
		$GLOBALS['links'][$link]        = $name;

		return $link;
	}

	function ldap_set_option($link, $option, $value) {
		if ($link === null && $option === LDAP_OPT_DEBUG_LEVEL) {
			$GLOBALS['calls']['debug_levels'][] = $value;
		}

		return true;
	}

	function ldap_start_tls($link) {
		$server = probe_server($link);

		if (!empty($server['down']) || !empty($server['tls_fails'])) {
			return probe_fail($link, -11);
		}

		return true;
	}

	function ldap_close($link) {
		return true;
	}

	function ldap_errno($link) {
		return $GLOBALS['links'][$link . '#errno'] ?? 0;
	}

	function ldap_error($link) {
		return 'probe error ' . ldap_errno($link);
	}

	function ldap_bind($link, $dn = null, $password = null) {
		$server = probe_server($link);

		$GLOBALS['calls']['binds'][] = array($GLOBALS['links'][$link], $dn);

		if (!empty($server['down'])) {
			return probe_fail($link, -1);
		}

		if ((string) $dn === '' && (string) $password === '') {
			return true;
		}

		foreach ($server['entries'] ?? array() as $entry) {
			if ($entry['dn'] === $dn && isset($entry['bind_errno'])) {
				return probe_fail($link, $entry['bind_errno']);
			}

			if (isset($entry['password']) && $entry['dn'] === $dn && $entry['password'] === $password) {
				return true;
			}
		}

		return probe_fail($link, 0x31);
	}

	/* every (attribute=value) assertion in the filter is ORed; enough for the filters Kadupul builds */
	function ldap_search($link, $base, $filter, $attributes = array()) {
		$server = probe_server($link);

		$GLOBALS['calls']['searches'][] = array($GLOBALS['links'][$link], $filter);

		if (!empty($server['down'])) {
			return probe_fail($link, -1);
		}

		preg_match_all('/\(([A-Za-z]+)=((?:[^()\\\\]|\\\\[0-9a-fA-F]{2})*)\)/', $filter, $assertions, PREG_SET_ORDER);

		$hits = array();

		foreach ($server['entries'] ?? array() as $entry) {
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

	function ldap_get_entries($link, $result) {
		$entries = array('count' => count($result));

		foreach ($result as $index => $entry) {
			$entries[$index] = array('dn' => $entry['dn']);

			foreach ($entry as $attribute => $value) {
				if ($attribute != 'dn' && $attribute != 'password') {
					$entries[$index][$attribute] = array('count' => 1, 0 => $value);
				}
			}
		}

		return $entries;
	}

	function ldap_count_entries($link, $result) {
		return count($result);
	}

	function ldap_first_entry($link, $result) {
		return $result[0] ?? false;
	}

	function ldap_get_dn($link, $entry) {
		return $entry['dn'];
	}

	function ldap_compare($link, $dn, $attribute, $value) {
		$groups = probe_server($link)['groups'] ?? array();

		if (!isset($groups[$dn][$attribute])) {
			return -1;
		}

		return in_array($value, $groups[$dn][$attribute], true);
	}
}

namespace {
	// nosemgrep: php.lang.security.eval-use.eval-use -- test-only evaluation of a repository-owned file
	eval('namespace LdapDirectoryProbe; ' . preg_replace('/^<\?php\s*/', '', $GLOBALS['scenario']['ldap_source']));

	if ($GLOBALS['scenario']['auth_source'] != '') {
		// nosemgrep: php.lang.security.eval-use.eval-use -- test-only evaluation of repository-owned functions
		eval('namespace LdapDirectoryProbe; ' . $GLOBALS['scenario']['auth_source']);
	}

	$started = hrtime(true);
	// nosemgrep: php.lang.security.eval-use.eval-use -- test-only call named by the test
	$result  = eval('namespace LdapDirectoryProbe; return ' . $GLOBALS['scenario']['call'] . ';');
	$elapsed = (hrtime(true) - $started) / 1e9;

	print json_encode(array(
		'result'    => $result,
		'error'     => $error,
		'error_msg' => $error_msg,
		'calls'     => $GLOBALS['calls'],
		'logs'      => $GLOBALS['logs'],
		'lockouts'  => $GLOBALS['lockouts'],
		'elapsed'   => $elapsed,
	));
}
PHP;
}

/**
 * @param array<string, mixed> $scenario       servers, config, request and the 'call' expression
 * @param array<int, string>   $auth_functions lib/auth.php functions to load beside lib/ldap.php
 *
 * @return array<string, mixed>
 */
function ldap_directory_probe_run(array $scenario, array $auth_functions = array()) : array {
	$root = dirname(__DIR__, 2);
	$auth = file_get_contents($root . '/lib/auth.php');

	$scenario['ldap_source'] = file_get_contents($root . '/lib/ldap.php');
	$scenario['auth_source'] = '';

	foreach ($auth_functions as $name) {
		$scenario['auth_source'] .= cacti_test_function_source($auth, $name) . "\n\n";
	}

	return cacti_test_run_php_source(ldap_directory_probe_source(), $scenario);
}

/**
 * Two servers that hold the same directory: alice binds with 'secret', and the
 * service account cn=search binds with 'search-secret'.
 *
 * @return array<string, mixed>
 */
function ldap_directory_probe_replicas(array $hosts = array('ldap1', 'ldap2')) : array {
	$entries = array(
		array('dn' => 'uid=alice,ou=people,dc=example,dc=com', 'password' => 'secret', 'uid' => 'alice', 'cn' => 'Alice Smith'),
		array('dn' => 'cn=search,dc=example,dc=com', 'password' => 'search-secret', 'cn' => 'search'),
	);

	$servers = array();

	foreach ($hosts as $host) {
		$servers[$host] = array('entries' => $entries);
	}

	return array(
		'servers' => $servers,
		'config'  => array(
			'ldap_server'            => implode(' ', $hosts),
			'ldap_port'              => '389',
			'ldap_port_ssl'          => '636',
			'ldap_version'           => '3',
			'ldap_encryption'        => '0',
			'ldap_referrals'         => '0',
			'ldap_mode'              => '2',
			'ldap_dn'                => 'uid=<username>,ou=people,dc=example,dc=com',
			'ldap_search_base'       => 'dc=example,dc=com',
			'ldap_search_filter'     => '(uid=<username>)',
			'ldap_specific_dn'       => 'cn=search,dc=example,dc=com',
			'ldap_specific_password' => 'search-secret',
		),
	);
}

/**
 * The shipped LdapError class and failover predicates, for login
 * harnesses that stub the directory calls but keep the failover decision real.
 */
function ldap_directory_failover_source() : string {
	$source = file_get_contents(dirname(__DIR__, 2) . '/lib/ldap.php');
	$start  = strpos($source, 'abstract class LdapError {');
	$end    = strpos($source, "\nclass Ldap {", $start);

	if ($start === false || $end === false) {
		throw new RuntimeException('LdapError not found in lib/ldap.php');
	}

	return substr($source, $start, $end - $start) . "\n" . cacti_test_function_source($source, 'cacti_ldap_server_unreachable') . "\n\n" .
		cacti_test_function_source($source, 'cacti_ldap_search_next_server') . "\n\n";
}
