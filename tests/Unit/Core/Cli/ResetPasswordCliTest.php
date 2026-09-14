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
 * The script runs unchanged from a temporary tree whose include/cli_check.php
 * stubs the database and loads the real password helpers from lib/auth.php,
 * so arguments, standard input, output and exit codes are all exercised. The
 * terminal path runs separately with its terminal calls stubbed.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function reset_password_cli_path() : string {
	return dirname(__DIR__, 4) . '/cli/reset_password.php';
}

/**
 * @param array<int, string>   $args
 * @param array<string, string> $env
 *
 * @return array{code: int, stdout: string, stderr: string}
 */
function reset_password_cli_process(string $file, array $args, string $stdin, array $env = array()) : array {
	$pipes = array();
	$proc  = proc_open(
		array_merge(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED), '-d', 'xdebug.mode=off', $file), $args),
		array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes,
		null,
		$env + getenv()
	);

	if (!is_resource($proc)) {
		throw new RuntimeException('unable to start the PHP child process');
	}

	fwrite($pipes[0], $stdin);
	fclose($pipes[0]);

	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);

	return array('code' => proc_close($proc), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr);
}

/**
 * Run the shipped script with a stubbed include/cli_check.php.
 *
 * @param array<int, string>   $args
 * @param array<string, mixed> $scenario
 *
 * @return array<string, mixed>
 */
function reset_password_cli_run(array $args, string $stdin = '', array $scenario = array()) : array {
	static $helpers = null;

	if ($helpers === null) {
		$auth    = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');
		$helpers = '';

		foreach (array('compat_password_hash', 'compat_password_verify', 'compat_hash_equals', 'secpass_check_pass', 'secpass_check_history') as $function) {
			$helpers .= cacti_test_function_source($auth, $function) . "\n\n";
		}
	}

	$root = sys_get_temp_dir() . '/cacti_reset_password_' . getmypid() . '_' . bin2hex(random_bytes(4));

	mkdir($root . '/cli', 0700, true);
	mkdir($root . '/include', 0700);

	$check = <<<'PHP'
<?php
$scenario = json_decode(file_get_contents(getenv('RESET_PASSWORD_SCENARIO')), true);

$config = array('poller_id' => $scenario['poller_id']);

$GLOBALS['probe'] = array('executed' => array(), 'logs' => array(), 'remote_switch' => 0, 'length_checks' => 0, 'transaction' => array());

define('COPYRIGHT_YEARS', '2004-2026');

register_shutdown_function(function () : void {
	file_put_contents(getenv('RESET_PASSWORD_RESULT'), json_encode($GLOBALS['probe']));
});

function cacti_sizeof($array) {
	return ($array === false || !is_array($array)) ? 0 : count($array);
}

function cacti_count($array) {
	return ($array === false || !is_array($array)) ? 0 : count($array);
}

function read_config_option($name, $force = false) {
	return $GLOBALS['scenario']['config'][$name] ?? '';
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
	$GLOBALS['probe']['logs'][] = $string;
}

function get_cacti_cli_version() {
	return '1.2.32 (DB: 1.2.32)';
}

function db_switch_remote_to_main() {
	$GLOBALS['probe']['remote_switch']++;
}

function db_fetch_row_prepared($sql, $params = array(), $log = true) {
	$user = $GLOBALS['scenario']['user'];

	if (!cacti_sizeof($user) || (string) $user['realm'] !== '0' || strpos($sql, 'realm = 0') === false) {
		return array();
	}

	/* secpass_check_history() reads only enabled accounts by id */
	if (strpos($sql, "enabled = 'on'") !== false) {
		return ($params[0] == $user['id'] && $user['enabled'] == 'on') ? $user : array();
	}

	return $params[0] === $user['username'] ? $user : array();
}

function db_execute_prepared($sql, $params = array(), $log = true) {
	$sql = trim(preg_replace('/\s+/', ' ', $sql));

	$GLOBALS['probe']['executed'][] = array('sql' => $sql, 'params' => $params);

	$fail = $GLOBALS['scenario']['fail_on'] ?? '';

	return !($fail !== '' && strpos($sql, $fail) === 0);
}

function db_begin_transaction($db_conn = false) {
	$GLOBALS['probe']['transaction'][] = 'begin';

	return empty($GLOBALS['scenario']['no_transaction']);
}

function db_commit_transaction($db_conn = false) {
	$GLOBALS['probe']['transaction'][] = 'commit';

	return empty($GLOBALS['scenario']['commit_fails']);
}

function db_rollback_transaction($db_conn = false) {
	$GLOBALS['probe']['transaction'][] = 'rollback';

	return true;
}

function db_check_password_length() {
	$GLOBALS['probe']['length_checks']++;

	if (!empty($GLOBALS['scenario']['length_dies'])) {
		die('Failed to determine password field length, can not continue as may corrupt password');
	}
}

PHP;

	file_put_contents($root . '/include/cli_check.php', $check . $helpers);
	copy(reset_password_cli_path(), $root . '/cli/reset_password.php');

	$scenario += array(
		'poller_id' => 1,
		'config'    => array(),
		'user'      => array('id' => 1, 'username' => 'admin', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => '', 'password_history' => ''),
	);

	file_put_contents($root . '/scenario.json', json_encode($scenario));

	try {
		$process = reset_password_cli_process($root . '/cli/reset_password.php', $args, $stdin, array(
			'RESET_PASSWORD_SCENARIO' => $root . '/scenario.json',
			'RESET_PASSWORD_RESULT'   => $root . '/result.json',
		));

		$probe = is_file($root . '/result.json') ? json_decode((string) file_get_contents($root . '/result.json'), true) : null;
	} finally {
		foreach (array('/cli/reset_password.php', '/include/cli_check.php', '/scenario.json', '/result.json') as $file) {
			if (is_file($root . $file)) {
				unlink($root . $file);
			}
		}

		rmdir($root . '/cli');
		rmdir($root . '/include');
		rmdir($root);
	}

	if (!is_array($probe)) {
		throw new RuntimeException('reset_password.php did not finish: ' . $process['stdout'] . $process['stderr']);
	}

	return $process + $probe;
}

/**
 * @param array<string, mixed> $result
 *
 * @return array<int, array{sql: string, params: array<int, mixed>}>
 */
function reset_password_cli_writes(array $result, string $prefix) : array {
	return array_values(array_filter($result['executed'], function (array $query) use ($prefix) : bool {
		return strpos($query['sql'], $prefix) === 0;
	}));
}

function reset_password_cli_user(array $fields = array()) : array {
	return $fields + array('id' => 1, 'username' => 'admin', 'realm' => 0, 'enabled' => 'on', 'locked' => '', 'password' => '', 'password_history' => '');
}

test('--help and --version exit 0 and never touch the database', function () {
	foreach (array('--help', '-h', '--version', '-V') as $flag) {
		$result = reset_password_cli_run(array($flag));

		expect($result['code'])->toBe(0, $flag)
			->and($result['stdout'])->toContain('Cacti Reset Password Utility')
			->and($result['executed'])->toBe(array());
	}
});

test('the help text never puts the password on a command line', function () {
	$help = reset_password_cli_run(array('--help'))['stdout'];

	expect($help)->toContain('usage: reset_password.php --username=<local user>')
		->and($help)->toContain('read -rs NEW_PASSWORD')
		->and($help)->toContain('<<< "$NEW_PASSWORD"')
		->and($help)->not->toContain('printf')
		->and($help)->not->toContain('| php');
});

test('missing, empty or unknown arguments exit 1 without reading a password', function () {
	$cases = array(
		'no arguments'        => array(),
		'empty username'      => array('--username='),
		'password argument'   => array('--username=admin', '--password=Recover-1234'),
		'unknown flag'        => array('--force'),
	);

	foreach ($cases as $name => $args) {
		$result = reset_password_cli_run($args, "Recover-1234\n");

		expect($result['code'])->toBe(1, $name)
			->and($result['executed'])->toBe(array())
			->and($result['length_checks'])->toBe(0);
	}

	expect(reset_password_cli_run(array('--password=Recover-1234'))['stdout'])->toContain('ERROR: Invalid Argument: (--password)');
});

test('a piped password is hashed as the web does and must be changed at the next login', function () {
	foreach (array('--username=admin', '-u=admin') as $arg) {
		$result = reset_password_cli_run(array($arg), "Recover-1234\r\n");
		$update = reset_password_cli_writes($result, 'UPDATE user_auth');

		expect($result['code'])->toBe(0, $arg)
			->and($result['stdout'])->toContain("Password reset for local user 'admin'")
			->and($update)->toHaveCount(1)
			->and($update[0]['sql'])->toContain("must_change_password = 'on'")
			->and($update[0]['sql'])->toContain("password_change = 'on'")
			->and($update[0]['sql'])->not->toContain('locked')
			->and($update[0]['params'][2])->toBe(1)
			->and(password_verify('Recover-1234', $update[0]['params'][0]))->toBeTrue()
			->and(password_get_info($update[0]['params'][0])['algo'])->toBe(password_get_info(password_hash('x', PASSWORD_DEFAULT))['algo']);
	}
});

test('a reset revokes tokens and sessions and never shows or logs the password', function () {
	$result = reset_password_cli_run(array('--username=admin'), "Recover-1234\n");
	$hash   = reset_password_cli_writes($result, 'UPDATE user_auth')[0]['params'][0];
	$shown  = $result['stdout'] . $result['stderr'] . implode("\n", $result['logs']);

	expect($result['executed'])->toContain(array('sql' => 'DELETE FROM user_auth_cache WHERE user_id = ?', 'params' => array(1)))
		->and($result['executed'])->toContain(array('sql' => 'DELETE FROM sessions WHERE user_id = ?', 'params' => array(1)))
		->and($result['transaction'])->toBe(array('begin', 'commit'))
		->and(implode("\n", $result['logs']))->toContain('admin')
		->and($shown)->not->toContain('Recover-1234')
		->and($shown)->not->toContain($hash);
});

test('a remote data collector switches to the main database first', function () {
	expect(reset_password_cli_run(array('--username=admin'), "Recover-1234\n", array('poller_id' => 2))['remote_switch'])->toBe(1)
		->and(reset_password_cli_run(array('--help'))['remote_switch'])->toBe(0);
});

test('an unknown or non-local account, an empty password or a failing rule exits 1 without writes', function () {
	$cases = array(
		'unknown user'   => array(array('--username=nobody'), "Recover-1234\n", array()),
		'LDAP account'   => array(array('--username=alice'), "Recover-1234\n", array('user' => reset_password_cli_user(array('id' => 9, 'username' => 'alice', 'realm' => 1001)))),
		'empty input'    => array(array('--username=admin'), '', array()),
		'blank line'     => array(array('--username=admin'), "\n", array()),
		'password rules' => array(array('--username=admin'), "short\n", array('config' => array('secpass_minlen' => 8))),
	);

	foreach ($cases as $name => $case) {
		$result = reset_password_cli_run($case[0], $case[1], $case[2]);

		expect($result['code'])->toBe(1, $name)
			->and($result['stdout'])->toContain('ERROR')
			->and($result['executed'])->toBe(array(), $name);
	}
});

test('the current or a previous password is refused when password history is on', function () {
	$user = reset_password_cli_user(array(
		'password'         => password_hash('Current-Pass-1', PASSWORD_DEFAULT),
		'password_history' => password_hash('Older-Pass-2', PASSWORD_DEFAULT),
	));

	foreach (array('Current-Pass-1', 'Older-Pass-2') as $reused) {
		$result = reset_password_cli_run(array('--username=admin'), $reused . "\n", array('user' => $user, 'config' => array('secpass_history' => 3)));

		expect($result['code'])->toBe(1, $reused)
			->and($result['stdout'])->toContain('You cannot use a previously entered password!')
			->and($result['executed'])->toBe(array());
	}
});

test('the current password is refused even when password history is off', function () {
	$current = password_hash('Current-Pass-1', PASSWORD_DEFAULT);

	$cases = array(
		'history off'           => array('user' => reset_password_cli_user(array('password' => $current))),
		'disabled with history' => array('user' => reset_password_cli_user(array('password' => $current, 'enabled' => '')), 'config' => array('secpass_history' => 3)),
	);

	foreach ($cases as $name => $scenario) {
		$result = reset_password_cli_run(array('--username=admin'), "Current-Pass-1\n", $scenario);

		expect($result['code'])->toBe(1, $name)
			->and($result['stdout'])->toContain('Your new password cannot be the same as the old password.')
			->and($result['transaction'])->toBe(array(), $name)
			->and($result['executed'])->toBe(array(), $name);
	}

	expect(reset_password_cli_run(array('--username=admin'), "Recover-1234\n", $cases['history off'])['code'])->toBe(0);
});

test('the replaced hash joins the password history as the web password change keeps it', function () {
	$user = reset_password_cli_user(array('password' => 'H0', 'password_history' => 'H1|H2'));

	$rotated = reset_password_cli_writes(reset_password_cli_run(array('--username=admin'), "Recover-1234\n", array('user' => $user, 'config' => array('secpass_history' => 2))), 'UPDATE user_auth');
	$first   = reset_password_cli_writes(reset_password_cli_run(array('--username=admin'), "Recover-1234\n", array('user' => reset_password_cli_user(array('password' => 'H0')), 'config' => array('secpass_history' => 3))), 'UPDATE user_auth');
	$off     = reset_password_cli_writes(reset_password_cli_run(array('--username=admin'), "Recover-1234\n", array('user' => $user)), 'UPDATE user_auth');

	/* auth_changepassword.php: drop the oldest until history - 1 remain, then append the replaced hash */
	expect($rotated[0]['params'][1])->toBe('H2|H0')
		->and($first[0]['params'][1])->toBe('|H0')
		->and($off[0]['params'][1])->toBe('H1|H2');
});

test('a disabled or locked account is reset with a NOTE and its state left alone', function () {
	$disabled = reset_password_cli_run(array('--username=admin'), "Recover-1234\n", array(
		'user'   => reset_password_cli_user(array('enabled' => '', 'password' => 'H0', 'password_history' => 'H1')),
		'config' => array('secpass_history' => 2),
	));
	$locked = reset_password_cli_run(array('--username=admin'), "Recover-1234\n", array('user' => reset_password_cli_user(array('locked' => 'on'))));
	$normal = reset_password_cli_run(array('--username=admin'), "Recover-1234\n");

	expect($disabled['code'])->toBe(0)
		->and($disabled['stdout'])->toContain('NOTE: This account is disabled')
		->and($disabled['stderr'])->toBe('')
		->and(reset_password_cli_writes($disabled, 'UPDATE user_auth')[0]['params'][1])->toBe('H1|H0')
		->and($locked['code'])->toBe(0)
		->and($locked['stdout'])->toContain('NOTE: This account is locked')
		->and(reset_password_cli_writes($locked, 'UPDATE user_auth')[0]['sql'])->not->toContain('locked')
		->and($normal['stdout'])->not->toContain('NOTE');
});

dataset('reset password write failures', array(
	'token revocation' => array('DELETE FROM user_auth_cache', 'revoke remember-me tokens'),
	'session end'      => array('DELETE FROM sessions', 'end sessions'),
	'password update'  => array('UPDATE user_auth', 'save the password'),
));

test('a failed write rolls back, names the step and exits 1', function ($fail_on, $step) {
	$result = reset_password_cli_run(array('--username=admin'), "Recover-1234\n", array('fail_on' => $fail_on));

	expect($result['code'])->toBe(1)
		->and($result['stdout'])->toContain("ERROR: Could not $step for local user 'admin'; the password was not changed")
		->and($result['stdout'])->not->toContain('Password reset')
		->and($result['transaction'])->toBe(array('begin', 'rollback'))
		->and($result['logs'])->toBe(array());
})->with('reset password write failures');

test('a failed commit rolls back and exits 1', function () {
	$result = reset_password_cli_run(array('--username=admin'), "Recover-1234\n", array('commit_fails' => true));

	expect($result['code'])->toBe(1)
		->and($result['stdout'])->toContain('ERROR: Could not commit the change')
		->and($result['stdout'])->not->toContain('Password reset')
		->and($result['transaction'])->toBe(array('begin', 'commit', 'rollback'));
});

test('a transaction that can not start exits 1 before any write', function () {
	$result = reset_password_cli_run(array('--username=admin'), "Recover-1234\n", array('no_transaction' => true));

	expect($result['code'])->toBe(1)
		->and($result['stdout'])->toContain("ERROR: Could not start a database transaction for local user 'admin'; the password was not changed")
		->and($result['stdout'])->not->toContain('Password reset')
		->and($result['transaction'])->toBe(array('begin'))
		->and($result['executed'])->toBe(array())
		->and($result['logs'])->toBe(array());
});

test('a password column check that dies exits 1 before any write', function () {
	$result = reset_password_cli_run(array('--username=admin'), "Recover-1234\n", array('length_dies' => true));

	expect($result['code'])->toBe(1)
		->and($result['stdout'])->toContain('Failed to determine password field length')
		->and($result['stdout'])->not->toContain('Password reset')
		->and($result['length_checks'])->toBe(1)
		->and($result['transaction'])->toBe(array())
		->and($result['executed'])->toBe(array());
});

/**
 * Run reset_password_read() as if STDIN were a terminal, with stty, fgets,
 * shutdown and signal calls recorded.
 *
 * @param array<string, mixed> $scenario
 *
 * @return array{code: int, stdout: string, stderr: string}
 */
function reset_password_tty_run(array $scenario) : array {
	$cli = file_get_contents(reset_password_cli_path());

	$child = <<<'PHP'
<?php
namespace ResetPasswordTty {
	$GLOBALS['scenario'] = json_decode(stream_get_contents(\STDIN), true);
	$GLOBALS['tty']      = array('stty' => array(), 'shutdown' => 0, 'async' => false, 'signals' => array());

	function stream_isatty($stream) {
		return true;
	}

	function shell_exec($command) {
		$GLOBALS['tty']['stty'][] = $command;
		print "\n[" . $command . "]\n";

		return '';
	}

	function fgets($stream) {
		if (!empty($GLOBALS['scenario']['interrupt']) && isset($GLOBALS['tty']['handler'])) {
			($GLOBALS['tty']['handler'])(\SIGINT, array());
		}

		return array_shift($GLOBALS['scenario']['lines']) ?? false;
	}

	function register_shutdown_function(callable $callback) {
		$GLOBALS['tty']['shutdown']++;

		\register_shutdown_function($callback);
	}

	function pcntl_async_signals($enable) {
		$GLOBALS['tty']['async'] = $enable;
	}

	function pcntl_signal($signal, $handler) {
		$GLOBALS['tty']['signals'][] = $signal;
		$GLOBALS['tty']['handler']   = $handler;

		return true;
	}
PHP;

	$child .= "\n\n" . cacti_test_function_source($cli, 'reset_password_echo_on') . "\n\n";
	$child .= cacti_test_function_source($cli, 'reset_password_read') . "\n";
	$child .= <<<'PHP'

	$password = reset_password_read();

	unset($GLOBALS['tty']['handler']);
	print "\nRESULT " . json_encode(array('password' => $password, 'tty' => $GLOBALS['tty'])) . "\n";
}
PHP;

	$file = tempnam(sys_get_temp_dir(), 'cacti_tty_');
	file_put_contents($file, $child);

	try {
		$process = reset_password_cli_process($file, array(), json_encode($scenario));
	} finally {
		unlink($file);
	}

	return $process;
}

/**
 * @param array{code: int, stdout: string, stderr: string} $process
 *
 * @return array<string, mixed>
 */
function reset_password_tty_result(array $process) : array {
	if (preg_match('/^RESULT (.*)$/m', $process['stdout'], $match) !== 1) {
		throw new RuntimeException('terminal probe did not finish: ' . $process['stdout'] . $process['stderr']);
	}

	return json_decode($match[1], true);
}

test('at a terminal the password is read twice without echo and echo comes back', function () {
	$process = reset_password_tty_run(array('lines' => array("Recover-1234\n", "Recover-1234\n")));
	$result  = reset_password_tty_result($process);

	expect($process['code'])->toBe(0)
		->and($result['password'])->toBe('Recover-1234')
		->and($result['tty']['stty'])->toBe(array('stty -echo', 'stty echo'))
		->and($result['tty']['shutdown'])->toBe(1)
		->and($process['stdout'])->toContain('New password:')
		->and($process['stdout'])->toContain('Confirm password:')
		->and(substr_count($process['stdout'], 'Recover-1234'))->toBe(1)
		/* the shutdown function turns echo on again even after a normal return */
		->and(substr($process['stdout'], -strlen("[stty echo]\n")))->toBe("[stty echo]\n");
});

test('mismatched terminal entries are refused and echo comes back', function () {
	$process = reset_password_tty_run(array('lines' => array("Recover-1234\n", "Recover-5678\n")));
	$result  = reset_password_tty_result($process);

	expect($result['password'])->toBeFalse()
		->and($process['stdout'])->toContain('ERROR: The passwords do not match')
		->and($result['tty']['stty'])->toBe(array('stty -echo', 'stty echo'));
});

test('Ctrl-C at the password prompt turns echo back on and exits 130', function () {
	$result = reset_password_tty_result(reset_password_tty_run(array('lines' => array("Recover-1234\n", "Recover-1234\n"))));

	expect($result['tty']['async'])->toBeTrue()
		->and($result['tty']['signals'])->toBe(array(SIGINT));

	$process = reset_password_tty_run(array('lines' => array("Recover-1234\n"), 'interrupt' => true));

	expect($process['code'])->toBe(130)
		->and($process['stdout'])->toContain('[stty -echo]')
		->and($process['stdout'])->toContain('[stty echo]')
		->and($process['stdout'])->not->toContain('RESULT');
})->skip(function () {
	return !extension_loaded('pcntl');
}, 'pcntl is not loaded');

test('the script follows the CLI conventions', function () {
	$source = file_get_contents(reset_password_cli_path());

	expect(strpos($source, "#!/usr/bin/env php\n<?php"))->toBe(0)
		->and($source)->toContain("require(__DIR__ . '/../include/cli_check.php');")
		->and($source)->toContain('stream_isatty(STDIN)')
		->and($source)->not->toContain('posix_isatty')
		->and(is_executable(reset_password_cli_path()))->toBeTrue();
});
