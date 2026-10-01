<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Login throttling is opt-in. When it is off, a login touches no throttle
 * state and behaves as in 1.2.31. When it is on, each Local, LDAP or Domains
 * attempt is counted against the client address and the login name before
 * any password check or directory call, and an attempt over either limit is
 * refused even with the right password.
 *
 * The shipped functions run in a child process against SQLite. The upsert is
 * rewritten from MySQL to SQLite syntax; both set every column from the
 * row's old values, so the two statements count the same way.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function login_throttle_run(array $config, array $steps) : array {
	$root  = dirname(__DIR__, 4);
	$auth  = file_get_contents($root . '/lib/auth.php');
	$maint = file_get_contents($root . '/poller_maintenance.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

define('POLLER_VERBOSITY_LOW', 2);
define('POLLER_VERBOSITY_DEBUG', 5);

$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec("CREATE TABLE user_auth_throttle (id CHAR(64) NOT NULL DEFAULT '' PRIMARY KEY, failures INTEGER NOT NULL DEFAULT 0, window_start INTEGER NOT NULL DEFAULT 0)");

$GLOBALS['users'] = array(
	'alice' => array('id' => 42, 'username' => 'alice', 'enabled' => 'on', 'locked' => '', 'password' => 'known-hash', 'password_change' => 'on'),
);
$GLOBALS['addr']          = '192.0.2.10';
$GLOBALS['req']           = array();
$GLOBALS['logs']          = array();
$GLOBALS['verify_calls']  = 0;
$GLOBALS['ldap_calls']    = 0;
$GLOBALS['throttle_sql']  = 0;

function throttle_sql($sql) {
	$GLOBALS['throttle_sql']++;

	return str_replace(
		array('VALUES(window_start)', 'ON DUPLICATE KEY UPDATE', 'IF('),
		array('excluded.window_start', 'ON CONFLICT (id) DO UPDATE SET', 'IIF('),
		$sql
	);
}

function get_nfilter_request_var($name, $default = '') {
	return $GLOBALS['req'][$name] ?? $default;
}

function get_client_addr() {
	return $GLOBALS['addr'];
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = 0) {
	if ($level === 0) {
		$GLOBALS['logs'][] = $message;
	}
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function read_config_option($name, $force = false) {
	return $GLOBALS['scenario']['config'][$name] ?? '';
}

function api_plugin_hook_function($name, $parm = null) {
	return $parm;
}

function auth_checkclear_lockout($username, $realm) {
}

function auth_process_lockout_check($username, $realm) {
	return false;
}

function auth_process_lockout($username, $realm) {
}

function db_column_exists($table, $column) {
	return true;
}

function db_fetch_row_prepared($sql, $params = array()) {
	return $GLOBALS['users'][$params[0]] ?? array();
}

function db_fetch_cell_prepared($sql, $params = array()) {
	global $pdo;

	if (strpos($sql, 'user_auth_throttle') !== false) {
		$statement = $pdo->prepare(throttle_sql($sql));
		$statement->execute($params);

		return $statement->fetchColumn();
	}

	return $GLOBALS['users'][$params[0]]['password'] ?? '';
}

function db_execute_prepared($sql, $params = array()) {
	global $pdo;

	if (strpos($sql, 'user_auth_throttle') !== false) {
		return $pdo->prepare(throttle_sql($sql))->execute($params);
	}

	return true;
}

function compat_password_verify($password, $hash) {
	$GLOBALS['verify_calls']++;

	return $hash === 'known-hash' && $password === 'right';
}

function compat_password_needs_rehash($password, $algo, $options = array()) {
	return false;
}

function cacti_ldap_search_dn($username) {
	$GLOBALS['ldap_calls']++;

	return array('error_num' => '0', 'dn' => 'uid=' . $username);
}

function cacti_ldap_auth($username, $password, $dn) {
	$GLOBALS['ldap_calls']++;

	return $password === 'right' ? array('error_num' => '0') : array('error_num' => '1', 'error_text' => 'Invalid credentials');
}

PHP;

	foreach (array('auth_log_username', 'auth_dummy_password_hash', 'auth_password_too_long', 'auth_login_throttle_keys', 'auth_login_throttle_check', 'auth_login_throttle_release', 'secpass_login_process', 'local_auth_login_process', 'ldap_login_process') as $name) {
		$source .= cacti_test_function_source($auth, $name) . "\n\n";
	}

	$source .= cacti_test_function_source($maint, 'login_throttle_purge') . "\n\n";
	$source .= <<<'PHP'
$results = array();

foreach ($scenario['steps'] as $step) {
	$error     = false;
	$error_msg = '';

	$GLOBALS['addr']         = $step['addr'] ?? '192.0.2.10';
	$GLOBALS['req']          = array('login_password' => $step['password'] ?? '');
	$GLOBALS['logs']         = array();
	$GLOBALS['verify_calls'] = 0;
	$GLOBALS['ldap_calls']   = 0;

	switch ($step['call']) {
		case 'local':
			$user = local_auth_login_process($step['username']);
			break;
		case 'ldap':
			$user = ldap_login_process($step['username']);
			break;
		case 'release':
			auth_login_throttle_release();
			$user = array();
			break;
		case 'purge':
			login_throttle_purge();
			$user = array();
			break;
		case 'age':
			$pdo->prepare('UPDATE user_auth_throttle SET window_start = window_start - ?')->execute(array($step['seconds']));
			$user = array();
			break;
	}

	$results[] = array(
		'user'         => $user['id'] ?? null,
		'error'        => $error,
		'error_msg'    => $error_msg,
		'verify_calls' => $GLOBALS['verify_calls'],
		'ldap_calls'   => $GLOBALS['ldap_calls'],
		'logs'         => $GLOBALS['logs'],
	);
}

print json_encode(array(
	'steps'        => $results,
	'rows'         => $pdo->query('SELECT failures FROM user_auth_throttle ORDER BY failures')->fetchAll(PDO::FETCH_COLUMN),
	'throttle_sql' => $GLOBALS['throttle_sql'],
));
PHP;

	return cacti_test_run_php_source($source, array('config' => $config, 'steps' => $steps));
}

function login_throttle_on(int $login = 3, int $addr = 50) : array {
	return array(
		'secpass_throttle'        => 'on',
		'secpass_throttle_login'  => (string) $login,
		'secpass_throttle_addr'   => (string) $addr,
		'secpass_throttle_window' => '15',
	);
}

function login_throttle_attempts(string $call, string $username, string $password, int $count, string $addr = '192.0.2.10') : array {
	return array_fill(0, $count, array('call' => $call, 'username' => $username, 'password' => $password, 'addr' => $addr));
}

test('with throttling off no throttle state is read or written and logins behave as before', function () {
	$steps   = login_throttle_attempts('local', 'alice', 'wrong', 20);
	$steps[] = array('call' => 'local', 'username' => 'alice', 'password' => 'right');
	$steps[] = array('call' => 'ldap', 'username' => 'bob', 'password' => 'right');
	$steps[] = array('call' => 'release');

	$result = login_throttle_run(array(), $steps);

	expect($result['throttle_sql'])->toBe(0)
		->and($result['steps'][20]['user'])->toBe(42)
		->and($result['steps'][20]['error'])->toBeFalse()
		->and($result['steps'][21]['ldap_calls'])->toBe(2);
});

test('a login name over its limit is refused before any hashing, even with the right password', function () {
	$steps   = login_throttle_attempts('local', 'alice', 'wrong', 3);
	$steps[] = array('call' => 'local', 'username' => 'alice', 'password' => 'right');

	$result = login_throttle_run(login_throttle_on(3), $steps);

	expect($result['steps'][2]['verify_calls'])->toBeGreaterThan(0)
		->and($result['steps'][3]['user'])->toBeNull()
		->and($result['steps'][3]['error'])->toBeTrue()
		->and($result['steps'][3]['error_msg'])->toBe('Too many failed login attempts.  Please try again later.')
		->and($result['steps'][3]['verify_calls'])->toBe(0)
		->and($result['steps'][3]['logs'][0])->toContain('Login throttled');
});

test('the login name count ignores case and the client address', function () {
	$steps = array(
		array('call' => 'local', 'username' => 'alice', 'password' => 'wrong', 'addr' => '192.0.2.10'),
		array('call' => 'local', 'username' => 'ALICE', 'password' => 'wrong', 'addr' => '198.51.100.7'),
		array('call' => 'local', 'username' => 'Alice', 'password' => 'right', 'addr' => '203.0.113.9'),
	);

	$result = login_throttle_run(login_throttle_on(2), $steps);

	expect($result['steps'][1]['error_msg'])->toBe('Access Denied!  Login Failed.')
		->and($result['steps'][2]['error_msg'])->toBe('Too many failed login attempts.  Please try again later.');
});

test('a client address over its limit is refused for every login name', function () {
	$steps = array();

	foreach (array('u1', 'u2', 'u3', 'alice') as $name) {
		$steps[] = array('call' => 'local', 'username' => $name, 'password' => $name == 'alice' ? 'right' : 'x', 'addr' => '2001:db8:1:2::' . count($steps));
	}

	$steps[] = array('call' => 'local', 'username' => 'alice', 'password' => 'right', 'addr' => '2001:db8:1:3::1');

	$result = login_throttle_run(login_throttle_on(10, 3), $steps);

	/* the first four share one /64; the last is in another */
	expect($result['steps'][2]['error_msg'])->toBe('Access Denied!  Login Failed.')
		->and($result['steps'][3]['user'])->toBeNull()
		->and($result['steps'][3]['error_msg'])->toBe('Too many failed login attempts.  Please try again later.')
		->and($result['steps'][4]['user'])->toBe(42);
});

test('IPv4 clients, including IPv4-mapped IPv6, are counted by their own address', function () {
	$steps = array(
		array('call' => 'local', 'username' => 'u1', 'password' => 'x', 'addr' => '::ffff:192.0.2.1'),
		array('call' => 'local', 'username' => 'u2', 'password' => 'x', 'addr' => '::ffff:192.0.2.1'),
		array('call' => 'local', 'username' => 'alice', 'password' => 'right', 'addr' => '::ffff:192.0.2.2'),
		array('call' => 'local', 'username' => 'alice', 'password' => 'right', 'addr' => '192.0.2.3'),
	);

	$result = login_throttle_run(login_throttle_on(10, 1), $steps);

	expect($result['steps'][1]['error_msg'])->toBe('Too many failed login attempts.  Please try again later.')
		->and($result['steps'][2]['user'])->toBe(42)
		->and($result['steps'][3]['user'])->toBe(42);
});

test('the count starts again once the window has passed', function () {
	$steps   = login_throttle_attempts('local', 'alice', 'wrong', 3);
	$steps[] = array('call' => 'age', 'seconds' => 15 * 60);
	$steps[] = array('call' => 'local', 'username' => 'alice', 'password' => 'right');

	$result = login_throttle_run(login_throttle_on(2), $steps);

	expect($result['steps'][2]['error_msg'])->toBe('Too many failed login attempts.  Please try again later.')
		->and($result['steps'][4]['user'])->toBe(42);
});

test('a successful login clears its login name count and returns only its own address count', function () {
	$steps   = login_throttle_attempts('local', 'u1', 'x', 2);
	$steps[] = array('call' => 'local', 'username' => 'alice', 'password' => 'wrong');
	$steps[] = array('call' => 'local', 'username' => 'alice', 'password' => 'right');
	$steps[] = array('call' => 'release');

	$result = login_throttle_run(login_throttle_on(), $steps);

	/* u1 keeps 2; the address keeps the 3 failures; alice's row is gone */
	expect($result['rows'])->toBe(array(2, 3));
});

test('LDAP attempts are throttled before the directory is contacted', function () {
	$steps   = login_throttle_attempts('ldap', 'bob', 'wrong', 2);
	$steps[] = array('call' => 'ldap', 'username' => 'bob', 'password' => 'right');

	$result = login_throttle_run(login_throttle_on(2), $steps);

	expect($result['steps'][1]['ldap_calls'])->toBe(2)
		->and($result['steps'][2]['ldap_calls'])->toBe(0)
		->and($result['steps'][2]['error_msg'])->toBe('Too many failed login attempts.  Please try again later.');
});

test('maintenance removes counts older than the longest window', function () {
	$steps   = login_throttle_attempts('local', 'u1', 'x', 1);
	$steps[] = array('call' => 'age', 'seconds' => 3601);
	$steps[] = array('call' => 'local', 'username' => 'u2', 'password' => 'x', 'addr' => '198.51.100.7');
	$steps[] = array('call' => 'purge');

	$result = login_throttle_run(login_throttle_on(), $steps);

	expect($result['rows'])->toBe(array(1, 1));
});

test('Domains attempts are counted after the realm is validated and before any directory call', function () {
	$body = cacti_test_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php'), 'domains_login_process');

	$realm    = strpos($body, 'get_auth_realms(true)');
	$throttle = strpos($body, 'auth_login_throttle_check($username, $realm)');
	$search   = strpos($body, 'domains_ldap_search_dn(');

	expect($realm)->toBeInt()
		->and($throttle)->toBeGreaterThan($realm)
		->and($search)->toBeGreaterThan($throttle);
});

test('the login page returns the count only after a non-guest login passes the account checks', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/auth_login.php');

	$transition = strpos($source, "cacti_auth_transition((int)\$user['id'], 'login')");
	$release    = strpos($source, "if (!\$guest_user) {\n\t\t\t\t\tauth_login_throttle_release();");

	expect(substr_count($source, 'auth_login_throttle_release('))->toBe(1)
		->and($transition)->toBeInt()
		->and($release)->toBeGreaterThan($transition);
});
