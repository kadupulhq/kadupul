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
 * cli/reset_password.php recovers a local account without the web interface.
 * It hashes the password as the web does, requires a new password at the next
 * login, revokes remember-me tokens and sessions, and never prints or logs the
 * password. It runs here in a child process with the database stubbed.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function reset_password_cli_source() : string {
	$source = @file_get_contents(dirname(__DIR__, 4) . '/cli/reset_password.php');

	if ($source === false) {
		throw new RuntimeException('cli/reset_password.php does not exist');
	}

	return $source;
}

/**
 * @param array<string, mixed> $scenario
 *
 * @return array<string, mixed>
 */
function reset_password_cli_run(array $scenario) : array {
	$auth = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');

	$child = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

$GLOBALS['executed'] = array();
$GLOBALS['logs']     = array();
$GLOBALS['length']   = 0;

function read_config_option($name, $force = false) {
	return $GLOBALS['scenario']['config'][$name] ?? '';
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
	$GLOBALS['logs'][] = $string;
}

function db_fetch_row_prepared($sql, $params = array(), $log = true) {
	$user = $GLOBALS['scenario']['user'];

	if (cacti_sizeof($user) && $params[0] === $user['username'] && strpos($sql, 'realm = 0') !== false && (string) $user['realm'] === '0') {
		return $user;
	}

	return array();
}

function db_execute_prepared($sql, $params = array(), $log = true) {
	$GLOBALS['executed'][] = array('sql' => trim(preg_replace('/\s+/', ' ', $sql)), 'params' => $params);

	return true;
}

function db_check_password_length() {
	$GLOBALS['length']++;
}

PHP;

	foreach (array('compat_password_hash', 'secpass_check_pass') as $function) {
		$child .= cacti_test_function_source($auth, $function) . "\n\n";
	}

	$child .= cacti_test_function_source(reset_password_cli_source(), 'reset_password_apply') . "\n\n";
	$child .= <<<'PHP'
ob_start();
$code   = reset_password_apply($scenario['username'], $scenario['password']);
$output = ob_get_clean();

print json_encode(array(
	'code'     => $code,
	'output'   => $output,
	'executed' => $GLOBALS['executed'],
	'logs'     => $GLOBALS['logs'],
	'length'   => $GLOBALS['length'],
));
PHP;

	return cacti_test_run_php_source($child, $scenario + array(
		'config' => array(),
		'user'   => array('id' => 1, 'username' => 'admin', 'realm' => 0, 'enabled' => 'on'),
	));
}

/**
 * @param array<string, mixed> $result
 *
 * @return array<int, array{sql: string, params: array<int, mixed>}>
 */
function reset_password_cli_writes(array $result, string $needle) : array {
	return array_values(array_filter($result['executed'], function (array $query) use ($needle) : bool {
		return strpos($query['sql'], $needle) !== false;
	}));
}

test('a local account gets a web-compatible hash and must choose a new password', function () {
	$result = reset_password_cli_run(array('username' => 'admin', 'password' => 'Recover-1234'));
	$update = reset_password_cli_writes($result, 'UPDATE user_auth');

	expect($result['code'])->toBe(0)
		->and($result['length'])->toBe(1)
		->and($update)->toHaveCount(1)
		->and($update[0]['sql'])->toContain("must_change_password = 'on'")
		->and($update[0]['sql'])->toContain("password_change = 'on'")
		->and($update[0]['params'][1])->toBe(1)
		->and(password_verify('Recover-1234', $update[0]['params'][0]))->toBeTrue()
		->and(password_get_info($update[0]['params'][0])['algo'])->toBe(password_get_info(password_hash('x', PASSWORD_DEFAULT))['algo']);
});

test('a reset revokes remember-me tokens and sessions for that account', function () {
	$result = reset_password_cli_run(array('username' => 'admin', 'password' => 'Recover-1234'));

	expect($result['executed'])->toContain(array('sql' => 'DELETE FROM user_auth_cache WHERE user_id = ?', 'params' => array(1)))
		->and($result['executed'])->toContain(array('sql' => 'DELETE FROM sessions WHERE user_id = ?', 'params' => array(1)));
});

test('the password never reaches the output or the log', function () {
	$result = reset_password_cli_run(array('username' => 'admin', 'password' => 'Recover-1234'));
	$hash   = reset_password_cli_writes($result, 'UPDATE user_auth')[0]['params'][0];

	expect($result['output'])->toContain('admin')
		->and($result['output'])->not->toContain('Recover-1234')
		->and($result['output'])->not->toContain($hash)
		->and(implode("\n", $result['logs']))->toContain('admin')
		->and(implode("\n", $result['logs']))->not->toContain('Recover-1234')
		->and(implode("\n", $result['logs']))->not->toContain($hash);
});

test('an unknown or non-local account is refused without writes', function () {
	$unknown = reset_password_cli_run(array('username' => 'nobody', 'password' => 'Recover-1234'));
	$ldap    = reset_password_cli_run(array(
		'username' => 'alice',
		'password' => 'Recover-1234',
		'user'     => array('id' => 9, 'username' => 'alice', 'realm' => 1001, 'enabled' => 'on'),
	));

	foreach (array($unknown, $ldap) as $result) {
		expect($result['code'])->toBe(1)
			->and($result['executed'])->toBe(array())
			->and($result['output'])->toContain('ERROR');
	}
});

test('an empty password or one that fails the password rules is refused without writes', function () {
	$empty  = reset_password_cli_run(array('username' => 'admin', 'password' => ''));
	$policy = reset_password_cli_run(array(
		'username' => 'admin',
		'password' => 'short',
		'config'   => array('secpass_minlen' => 8),
	));

	expect($empty['code'])->toBe(1)
		->and($empty['executed'])->toBe(array())
		->and($policy['code'])->toBe(1)
		->and($policy['executed'])->toBe(array())
		->and($policy['output'])->toContain('Password must be at least 8 characters');
});

test('the script follows the CLI conventions and takes no password argument', function () {
	$source = reset_password_cli_source();

	expect(strpos($source, "#!/usr/bin/env php\n<?php"))->toBe(0)
		->and($source)->toContain("require(__DIR__ . '/../include/cli_check.php');")
		->and($source)->toContain("case '--help':")
		->and($source)->toContain('exit(0);')
		->and($source)->toContain('exit(1);')
		->and($source)->toContain('STDIN')
		->and($source)->not->toContain("'--password'")
		->and(is_executable(dirname(__DIR__, 4) . '/cli/reset_password.php'))->toBeTrue();
});
