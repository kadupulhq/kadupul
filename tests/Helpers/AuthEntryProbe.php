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
 * Runs the shipped include/auth.php and lib/auth.php in a child PHP process
 * against an in-memory user_auth table.
 *
 * include/auth.php runs at file scope and may exit, and lib/auth.php calls the
 * global db_* helpers directly, so the stubs must be the first definitions the
 * process sees. A fresh process per scenario guarantees that even when another
 * test in the same Pest run has loaded the real database layer.
 *
 * Required from a test, this file only defines the runner functions. Executed
 * directly, it reads a JSON scenario on stdin and prints one JSON result.
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
	if (!function_exists('cacti_test_run_php_file')) {
		/**
		 * @param array<string, mixed> $scenario
		 *
		 * @return array<string, mixed>
		 */
		function cacti_test_run_php_file(string $file, array $scenario) : array {
			$pipes = array();
			$proc  = proc_open(
				array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED), '-d', 'xdebug.mode=off', $file),
				array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
				$pipes
			);

			if (!is_resource($proc)) {
				throw new RuntimeException('unable to start the PHP child process');
			}

			fwrite($pipes[0], json_encode($scenario));
			fclose($pipes[0]);

			$stdout = stream_get_contents($pipes[1]);
			$stderr = stream_get_contents($pipes[2]);

			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($proc);

			$decoded = json_decode((string) $stdout, true);

			if (!is_array($decoded)) {
				throw new RuntimeException('child process failed: ' . $stdout . $stderr);
			}

			return $decoded;
		}

		/**
		 * @param array<string, mixed> $scenario
		 *
		 * @return array<string, mixed>
		 */
		function cacti_test_run_auth_entry_probe(array $scenario) : array {
			return cacti_test_run_php_file(__FILE__, $scenario);
		}

		/**
		 * Run PHP source in a child process; the source reads the scenario from
		 * STDIN and prints a JSON object.
		 *
		 * @param array<string, mixed> $scenario
		 *
		 * @return array<string, mixed>
		 */
		function cacti_test_run_php_source(string $source, array $scenario) : array {
			$file = tempnam(sys_get_temp_dir(), 'cacti_auth_');

			if ($file === false || file_put_contents($file, $source) === false) {
				throw new RuntimeException('unable to write the child process source');
			}

			try {
				return cacti_test_run_php_file($file, $scenario);
			} finally {
				unlink($file);
			}
		}

		/**
		 * Return the source of a top-level function, from its declaration to the
		 * matching closing brace.
		 */
		function cacti_test_function_source(string $src, string $name) : string {
			$start = strpos($src, "\nfunction $name(");

			if ($start === false) {
				throw new RuntimeException("$name() not found");
			}

			$depth = 0;
			$len   = strlen($src);

			for ($i = strpos($src, '{', $start); $i < $len; $i++) {
				if ($src[$i] === '{') {
					$depth++;
				} elseif ($src[$i] === '}') {
					$depth--;

					if ($depth === 0) {
						return substr($src, $start + 1, $i - $start);
					}
				}
			}

			throw new RuntimeException("$name() is unbalanced");
		}
	}

	return;
}

$root     = dirname(__DIR__, 2);
$scenario = json_decode(stream_get_contents(STDIN), true);

if (!is_array($scenario)) {
	fwrite(STDERR, 'AuthEntryProbe: invalid scenario JSON on stdin');
	exit(1);
}

define('CACTI_VERSION', '1.2.32');
define('POLLER_VERBOSITY_NONE', 1);
define('POLLER_VERBOSITY_LOW', 2);
define('POLLER_VERBOSITY_MEDIUM', 3);
define('POLLER_VERBOSITY_HIGH', 4);
define('POLLER_VERBOSITY_DEBUG', 5);
define('POLLER_VERBOSITY_DEVDBG', 6);
define('OPER_MODE_NATIVE', 0);
define('OPER_MODE_RESKIN', 2);
define('COPYRIGHT_YEARS_SHORT', '2004-2026');

$GLOBALS['probe'] = array(
	'config'         => $scenario['config'] ?? array(),
	'users'          => $scenario['users'] ?? array(),
	'cache'          => $scenario['cache'] ?? array(),
	'realms'         => $scenario['realms'] ?? null,
	'groups'         => $scenario['groups'] ?? array(),
	'group_members'  => $scenario['group_members'] ?? array(),
	'group_realms'   => $scenario['group_realms'] ?? array(),
	'executed'       => array(),
	'events'         => array(),
	'config_writes'  => array(),
	'return'         => null,
	'page_continued' => false,
	'page'           => $scenario['page'] ?? 'probe.php',
);

function probe_normalize_sql(string $sql) : string {
	return trim(preg_replace('/\s+/', ' ', $sql));
}

/**
 * Evaluate the equality filters of a user_auth WHERE clause against the
 * in-memory table. Placeholders bind to $params in order.
 *
 * @return array<int, array<string, mixed>>
 */
function probe_user_rows(string $sql, array $params) : array {
	$where = stristr($sql, 'WHERE');
	$rows  = array();

	if ($where === false) {
		return array_values($GLOBALS['probe']['users']);
	}

	preg_match_all("/`?([a-z_]+)`?\s*=\s*(\?|'[^']*'|\d+)/i", $where, $matches, PREG_SET_ORDER);

	$bind    = 0;
	$filters = array();

	foreach ($matches as $match) {
		$filters[] = array($match[1], $match[2] === '?' ? $params[$bind++] : trim($match[2], "'"));
	}

	foreach ($GLOBALS['probe']['users'] as $row) {
		foreach ($filters as $filter) {
			if (!array_key_exists($filter[0], $row) || (string) $row[$filter[0]] !== (string) $filter[1]) {
				continue 2;
			}
		}

		$rows[] = $row;
	}

	return $rows;
}

/**
 * Run a query that joins the realm tables against an in-memory SQLite copy of
 * the scenario, so syntax and column names are checked by a real SQL engine
 * rather than by pattern matching. An engine error returns false, as the
 * database layer does.
 *
 * @return mixed
 */
function probe_realm_cell(string $sql, array $params) {
	static $pdo = null;

	if ($pdo === null) {
		$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));

		$pdo->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, enabled TEXT)');
		$pdo->exec('CREATE TABLE user_auth_realm (realm_id INTEGER, user_id INTEGER)');
		$pdo->exec('CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT)');
		$pdo->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
		$pdo->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)');

		$seed = array(
			'INSERT INTO user_auth (id, username, enabled) VALUES (?, ?, ?)' => array_map(function (array $row) : array {
				return array($row['id'], $row['username'], $row['enabled']);
			}, $GLOBALS['probe']['users']),
			'INSERT INTO user_auth_realm (user_id, realm_id) VALUES (?, ?)'         => $GLOBALS['probe']['realms'],
			'INSERT INTO user_auth_group (id, enabled) VALUES (?, ?)'               => $GLOBALS['probe']['groups'],
			'INSERT INTO user_auth_group_members (group_id, user_id) VALUES (?, ?)' => $GLOBALS['probe']['group_members'],
			'INSERT INTO user_auth_group_realm (group_id, realm_id) VALUES (?, ?)'  => $GLOBALS['probe']['group_realms'],
		);

		foreach ($seed as $insert => $rows) {
			$statement = $pdo->prepare($insert);

			foreach ($rows as $row) {
				$statement->execute($row);
			}
		}
	}

	try {
		$statement = $pdo->prepare($sql);
		$statement->execute($params);
	} catch (PDOException $e) {
		return false;
	}

	return $statement->fetchColumn();
}

function read_config_option($name, $force = false) {
	return $GLOBALS['probe']['config'][$name] ?? '';
}

function set_config_option($name, $value, $remote = false) {
	$GLOBALS['probe']['config'][$name]  = $value;
	$GLOBALS['probe']['config_writes'][] = array($name, $value);
}

function db_fetch_row_prepared($sql, $params = array(), $log = true) {
	if (strpos($sql, 'FROM user_auth') === false || strpos($sql, 'user_auth_') !== false) {
		return array();
	}

	$rows = probe_user_rows($sql, $params);

	return $rows[0] ?? array();
}

function db_fetch_cell_prepared($sql, $params = array(), $col_name = '', $log = true) {
	if (strpos($sql, 'FROM user_auth_cache') !== false) {
		foreach ($GLOBALS['probe']['cache'] as $row) {
			if ($row['user_id'] == $params[0] && $row['token'] === $params[1] && $row['hostname'] === $params[2]) {
				return $row['user_id'];
			}
		}

		return false;
	}

	if ($GLOBALS['probe']['realms'] !== null && strpos($sql, 'user_auth_realm') !== false) {
		return probe_realm_cell($sql, $params);
	}

	/* SELECT TOP is not MySQL syntax; the real server rejects it */
	if (strpos($sql, 'TOP 1') !== false || !preg_match('/SELECT\s+`?([a-z_]+)`?\s+FROM user_auth\b/i', $sql, $match)) {
		return false;
	}

	$rows = probe_user_rows($sql, $params);

	return isset($rows[0]) ? $rows[0][$match[1]] : false;
}

function db_fetch_cell($sql, $col_name = '', $log = true) {
	return db_fetch_cell_prepared($sql, array(), $col_name, $log);
}

function db_execute_prepared($sql, $params = array(), $log = true) {
	$GLOBALS['probe']['executed'][] = array('sql' => probe_normalize_sql($sql), 'params' => $params);

	return true;
}

function db_execute($sql, $log = true) {
	$GLOBALS['probe']['executed'][] = array('sql' => probe_normalize_sql($sql), 'params' => array());

	return true;
}

function db_table_exists($table, $log = true) {
	return true;
}

function cacti_sizeof($array) {
	return ($array === false || !is_array($array)) ? 0 : sizeof($array);
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function html_escape($string) {
	return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8');
}

function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
}

function get_cacti_version() {
	return CACTI_VERSION;
}

function get_current_page($basename = true) {
	return $GLOBALS['probe']['page'];
}

function api_plugin_hook_function($name, $parm = null) {
	return $parm;
}

function validate_redirect_url($url = '', $default = 'index.php') {
	return $default;
}

function get_guest_account() {
	$guest = (string) ($GLOBALS['probe']['config']['guest_user'] ?? '');

	/* mirrors lib/functions.php: match by username or id, enabled or not */
	foreach ($GLOBALS['probe']['users'] as $row) {
		if ($guest !== '' && ($row['username'] === $guest || (string) $row['id'] === $guest)) {
			return (string) $row['id'];
		}
	}

	return 0;
}

function get_client_addr() {
	return '192.0.2.10';
}

function kill_session_var($var_name) {
	unset($_SESSION[$var_name]);
}

function cacti_session_start($regenerate = false) {
	$GLOBALS['probe']['events'][] = 'session_start';
}

function cacti_session_destroy() {
	$GLOBALS['probe']['events'][] = 'session_destroy';
	$_SESSION = array();
}

function cacti_cookie_logout() {
	$GLOBALS['probe']['events'][] = 'cookie_logout';
}

function cacti_cookie_session_set($user, $realm, $secret) {
	$GLOBALS['probe']['events'][] = 'cookie_set';
}

function cacti_cookie_session_logout() {
	$GLOBALS['probe']['events'][] = 'cookie_session_logout';
}

function html_common_header($title, $selectedTheme = '') {
	$GLOBALS['probe']['events'][] = 'error_page';
}

function raise_ajax_permission_denied() {
}

class CactiSecureHeaders {
	public static function getNonceAttribute() {
		return '';
	}
}

require_once $root . '/lib/auth.php';

/* include/auth.php loads global.php by relative path and auth_login.php from
 * base_path; point both at stand-ins so the probe controls what runs. */
$probe_dir = sys_get_temp_dir() . '/cacti_auth_entry_' . getmypid() . '_' . bin2hex(random_bytes(4));
mkdir($probe_dir, 0700);
file_put_contents($probe_dir . '/global.php', "<?php\n");
file_put_contents($probe_dir . '/auth_login.php', "<?php\n\$GLOBALS['probe']['events'][] = 'login_page';\nexit;\n");
set_include_path($probe_dir);

register_shutdown_function(function () use ($probe_dir) : void {
	while (ob_get_level() > 0) {
		ob_end_clean();
	}

	unlink($probe_dir . '/global.php');
	unlink($probe_dir . '/auth_login.php');
	rmdir($probe_dir);

	print json_encode(array(
		'return'         => $GLOBALS['probe']['return'],
		'session'        => $_SESSION,
		'executed'       => $GLOBALS['probe']['executed'],
		'events'         => $GLOBALS['probe']['events'],
		'config_writes'  => $GLOBALS['probe']['config_writes'],
		'page_continued' => $GLOBALS['probe']['page_continued'],
	));
});

$config = array(
	'cacti_db_version' => CACTI_VERSION,
	'base_path'        => $probe_dir,
	'url_path'         => '/cacti/',
);

$_SESSION = $scenario['session'] ?? array();

foreach (array('PHP_AUTH_USER', 'REMOTE_USER', 'REDIRECT_REMOTE_USER', 'HTTP_PHP_AUTH_USER', 'HTTP_REMOTE_USER', 'HTTP_REDIRECT_REMOTE_USER', 'HTTP_REFERER') as $key) {
	unset($_SERVER[$key]);
}

foreach (($scenario['server'] ?? array()) as $key => $value) {
	$_SERVER[$key] = $value;
}

if (isset($scenario['cookie'])) {
	$_COOKIE['cacti_remembers'] = $scenario['cookie'];
}

ob_start();

$call = $scenario['call'] ?? array('type' => 'include_auth');

if ($call['type'] === 'include_auth') {
	/* realm -1 lets an authenticated request through without a realm lookup */
	$user_auth_realm_filenames = $scenario['realm_filenames'] ?? array('probe.php' => -1);

	if (!empty($scenario['guest_account'])) {
		$guest_account = true;
	}

	require $root . '/include/auth.php';

	$GLOBALS['probe']['page_continued'] = true;
} elseif (in_array($call['type'], array('get_basic_auth_username', 'cacti_auth_transition', 'check_auth_cookie'), true)) {
	$GLOBALS['probe']['return'] = call_user_func_array($call['type'], $call['args'] ?? array());
} else {
	fwrite(STDERR, 'AuthEntryProbe: unknown call type ' . $call['type']);
	exit(1);
}
