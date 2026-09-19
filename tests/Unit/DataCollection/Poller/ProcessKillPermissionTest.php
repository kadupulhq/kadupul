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
*/

/*
 * Timeout and cleanup paths signal a live registered task and then retire its
 * row. When the task was started by another user, the signal fails with EPERM.
 * Retiring the row anyway left the task running unregistered, and the timeout
 * branch of register_process_start() or the automation network master then
 * started a second copy beside it.
 *
 * Each scenario runs in a child PHP with posix_kill() and
 * posix_get_last_error() disabled and redefined, as in
 * ProcessStillRunningPermissionTest, and with the database and log stubbed as
 * in PollerSystemPidBranchesTest. The registered pid is the child's own, so
 * the /proc identity check matches and only the signal result differs.
 *
 * Library routines are required. The routines in cli/float_rrdfiles.php,
 * cli/rebuild_poller_cache.php, cli/batchgapfix.php, poller_commands.php and
 * poller_automation.php live in scripts that parse arguments, connect to the
 * database and exit when included, so the function or block under test is
 * extracted from the file and evaluated in the child instead. The evaluated
 * code is this repository's own source, never external input.
 */

const PROCESS_KILL_PERMISSION_EPERM = 1;
const PROCESS_KILL_PERMISSION_ESRCH = 3;

/**
 * Read one function or block from a repository file.
 *
 * @param string $file    Repository-relative path.
 * @param string $pattern Multiline regular expression matching the code.
 *
 * @return string The matched source.
 */
function process_kill_permission_extract($file, $pattern) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

	expect($source)->not->toBeFalse($file . ' must be readable');
	expect(preg_match($pattern, $source, $match))->toBe(1, $file . ' no longer has the code under test');

	return $match[0];
}

/**
 * Build the child code that runs one timeout or cleanup path.
 *
 * @param string $action Path to run.
 *
 * @return string PHP code, evaluated after the stubs and lib/poller.php.
 */
function process_kill_permission_invoke($action) {
	$base = dirname(__DIR__, 4);

	switch ($action) {
		case 'register':
			return '$result = register_process_start("poller", "test", 0, 300);';
		case 'timeout':
			return '$result = timeout_kill_registered_processes();';
		case 'dsstats':
			return '$type = ""; require ' . var_export($base . '/lib/dsstats.php', true) . '; $result = dsstats_kill_running_processes();';
		case 'rrdcheck':
			return '$type = ""; require ' . var_export($base . '/lib/rrdcheck.php', true) . '; $result = rrdcheck_kill_running_processes();';
		case 'float':
			return 'eval(' . var_export(process_kill_permission_extract('cli/float_rrdfiles.php', '/^function float_kill_running_processes\(.*?^}\n/ms'), true) . '); $result = float_kill_running_processes();';
		case 'pushout':
			return 'eval(' . var_export(process_kill_permission_extract('cli/rebuild_poller_cache.php', '/^function pushout_kill_running_processes\(.*?^}\n/ms'), true) . '); $result = pushout_kill_running_processes();';
		case 'commands':
			return 'eval(' . var_export(process_kill_permission_extract('poller_commands.php', '/^function commands_kill_running_processes\(.*?^}\n/ms'), true) . '); $result = commands_kill_running_processes();';
		case 'batchgapfix':
			return '$force = true; eval(' . var_export(process_kill_permission_extract('cli/batchgapfix.php', '/^\tif \(\$force\) \{\n.*?^\t\}\n/ms'), true) . ');';
		case 'automation':
			return '$network_id = 1; $poller_id = 1;'
				. 'eval(' . var_export(process_kill_permission_extract('poller_automation.php', '/^function isProcessRunning\(.*?^}\n/ms'), true) . ');'
				. 'eval(' . var_export(process_kill_permission_extract('poller_automation.php', '/^function killProcess\(.*?^}\n/ms'), true) . ');'
				. 'eval(' . var_export(process_kill_permission_extract('poller_automation.php', '/^\t\/\/ Remove any stale entries\n.*?^\tregisterTask\(\$network_id, getmypid\(\), \$poller_id, \'tmaster\'\);\n/ms'), true) . ');';
	}

	throw new InvalidArgumentException('unknown action ' . $action);
}

/**
 * Run one timeout or cleanup path against a registered row whose signals fail
 * with a chosen errno.
 *
 * @param string     $action  Path to run.
 * @param int        $errno   Error every signal to the registered pid reports,
 *                            or 0 for a signal that is delivered.
 * @param int|string $pid     Registered pid, or 'self' for the child's own pid.
 * @param int        $expired Nonzero when the row has exceeded its timeout.
 *
 * @return array{result: mixed, log: array<int, string>, writes: array<int, string>, output: string}
 */
function process_kill_permission_scenario($action, $errno, $pid = 'self', $expired = 1720000000) {
	$code    = 'define("POLLER_VERBOSITY_MEDIUM", 2);'
		. '$target = ' . var_export($pid, true) . ' === "self" ? getmypid() : ' . var_export($pid, true) . ';'
		. '$stub_errno = 0; $log = array(); $writes = array(); $result = null;'
		. '$row = array("tasktype" => "poller", "taskname" => "child", "taskid" => 0, "pid" => $target, "timeout" => 300, "timeout_exceeded" => ' . (int) $expired . ', "current_timestamp" => 1720000600);'
		. 'function posix_kill($pid, $signal) { global $target, $stub_errno; if ((int) $pid === (int) $target) { $stub_errno = ' . (int) $errno . '; return $stub_errno === 0; } $stub_errno = 0; return true; }'
		. 'function posix_get_last_error() { global $stub_errno; return $stub_errno; }'
		. 'function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }'
		. 'function array_rekey($array, $key, $key_value) { $out = array(); foreach ($array as $item) { $out[$item[$key]] = $item[$key_value]; } return $out; }'
		. 'function cacti_log($message, $output = false, $environ = "CMDPHP", $level = 0) { global $log; $log[] = $message; return true; }'
		. 'function automation_debug($message) {}'
		. 'function registerTask($network_id, $pid, $poller_id, $task = "collector") { global $writes; $writes[] = "REGISTER"; }'
		. 'function db_table_exists($table) { return true; }'
		. 'function db_execute_prepared($sql, $params = array()) { global $writes; $writes[] = strtok(trim($sql), " \n\t"); return true; }'
		. 'function db_fetch_row_prepared($sql, $params = array()) { global $row; return $row; }'
		. 'function db_fetch_assoc_prepared($sql, $params = array()) { global $row; return array($row); }'
		. 'require ' . var_export(dirname(__DIR__, 4) . '/lib/poller.php', true) . ';'
		/* The automation block exits when it declines to start, so report from shutdown. */
		. 'ob_start();'
		. 'register_shutdown_function(function () { global $result, $log, $writes; $output = ob_get_level() ? ob_get_clean() : ""; echo json_encode(array("result" => $result, "log" => $log, "writes" => $writes, "output" => $output)); });'
		. process_kill_permission_invoke($action);
	$pipes   = array();
	$process = proc_open(array(PHP_BINARY, '-d', 'disable_functions=posix_kill,posix_get_last_error', '-r', $code), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

	expect($process)->not->toBeFalse();

	$stdout = stream_get_contents($pipes[1]);
	$error  = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);

	// The signal stub never makes the target exit; forced repair must abort.
	expect(proc_close($process))->toBe($action === 'batchgapfix' ? 1 : 0, $error);

	$out = json_decode($stdout, true);

	expect($out)->toBeArray($stdout . $error);

	return $out;
}

test('a timed-out task started by another user keeps its row and blocks a replacement', function () {
	$out = process_kill_permission_scenario('register', PROCESS_KILL_PERMISSION_EPERM);

	expect($out['result'])->toBeFalse()
		->and($out['writes'])->toBe(array())
		->and(implode("\n", $out['log']))->toContain('another user');
});

test('a timed-out task this user started is still killed and replaced', function () {
	$out = process_kill_permission_scenario('register', 0);

	expect($out['result'])->toBeTrue()
		->and($out['writes'])->toBe(array('DELETE', 'INSERT'))
		->and(implode("\n", $out['log']))->toContain('Process being killed due to timeout!')
		->and(implode("\n", $out['log']))->not->toContain('another user');
});

test('a stale row naming a reserved pid is retired rather than treated as running', function () {
	/* Signal 0 to pid 1 answers EPERM for an unprivileged account. Liveness must
	   not read that as a live task, or the row blocks every later start. */
	$out = process_kill_permission_scenario('register', PROCESS_KILL_PERMISSION_EPERM, 1, 0);

	expect($out['result'])->toBeTrue()
		->and($out['writes'])->toBe(array('DELETE', 'INSERT'))
		->and(implode("\n", $out['log']))->not->toContain('Old process still running');
});

test('the timeout sweep keeps the row of a task it may not signal', function () {
	$out = process_kill_permission_scenario('timeout', PROCESS_KILL_PERMISSION_EPERM);

	expect($out['writes'])->toBe(array())
		->and(implode("\n", $out['log']))->toContain('another user')
		->and(implode("\n", $out['log']))->not->toContain('Process killed due to timeout!');
});

test('the timeout sweep still kills and retires a task this user started', function () {
	$out = process_kill_permission_scenario('timeout', 0);

	/* One line, in the 1.2.31 wording, and nothing about another user. */
	expect($out['writes'])->toBe(array('DELETE'))
		->and($out['log'])->toHaveCount(1)
		->and($out['log'][0])->toStartWith('ERROR: Process killed due to timeout! (poller, child, 0, ');
});

test('the timeout sweep retires the row of a task that is already gone', function () {
	$out = process_kill_permission_scenario('timeout', PROCESS_KILL_PERMISSION_ESRCH, 999999999);

	expect($out['writes'])->toBe(array('DELETE'))
		->and(implode("\n", $out['log']))->toContain('Detected process that is gone and did not unregister first!');
});

/**
 * Run timeout_kill_registered_processes() against a row whose pid answers
 * alive to every liveness probe but reports ESRCH the moment the real kill
 * signal is sent, modelling a process that exits in the window between the
 * probe and the kill.
 *
 * @return array{log: array<int, string>, writes: array<int, string>}
 */
function process_kill_permission_exit_during_kill() {
	$code    = 'define("POLLER_VERBOSITY_MEDIUM", 2);'
		. '$target = getmypid(); $exited = false; $log = array(); $writes = array(); $result = null;'
		. '$row = array("tasktype" => "poller", "taskname" => "child", "taskid" => 0, "pid" => $target, "timeout" => 300, "timeout_exceeded" => 1720000000, "current_timestamp" => 1720000600);'
		. 'function posix_kill($pid, $signal) { global $target, $exited; if ((int) $pid !== (int) $target) { return true; } if ($signal !== 0) { $exited = true; return false; } return !$exited; }'
		. 'function posix_get_last_error() { global $exited; return $exited ? ' . PROCESS_KILL_PERMISSION_ESRCH . ' : 0; }'
		. 'function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }'
		. 'function array_rekey($array, $key, $key_value) { $out = array(); foreach ($array as $item) { $out[$item[$key]] = $item[$key_value]; } return $out; }'
		. 'function cacti_log($message, $output = false, $environ = "CMDPHP", $level = 0) { global $log; $log[] = $message; return true; }'
		. 'function db_table_exists($table) { return true; }'
		. 'function db_execute_prepared($sql, $params = array()) { global $writes; $writes[] = strtok(trim($sql), " \n\t"); return true; }'
		. 'function db_fetch_row_prepared($sql, $params = array()) { global $row; return $row; }'
		. 'function db_fetch_assoc_prepared($sql, $params = array()) { global $row; return array($row); }'
		. 'require ' . var_export(dirname(__DIR__, 4) . '/lib/poller.php', true) . ';'
		. '$result = timeout_kill_registered_processes();'
		. 'echo json_encode(array("result" => $result, "log" => $log, "writes" => $writes));';
	$pipes   = array();
	$process = proc_open(array(PHP_BINARY, '-d', 'disable_functions=posix_kill,posix_get_last_error', '-r', $code), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

	expect($process)->not->toBeFalse();

	$stdout = stream_get_contents($pipes[1]);
	$error  = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);

	expect(proc_close($process))->toBe(0, $error);

	$out = json_decode($stdout, true);

	expect($out)->toBeArray($stdout . $error);

	return $out;
}

test('the timeout sweep treats a kill that fails because the process already exited as gone', function () {
	$out = process_kill_permission_exit_during_kill();

	expect($out['writes'])->toBe(array('DELETE'))
		->and($out['log'])->toHaveCount(1)
		->and($out['log'][0])->toContain('Detected process that is gone and did not unregister first!')
		->and($out['log'][0])->not->toContain('killed due to timeout');
});

dataset('cleanup routines', array('dsstats', 'rrdcheck', 'float', 'pushout', 'commands', 'batchgapfix'));

test('a cleanup routine keeps the row of a child it may not signal', function ($action) {
	$out = process_kill_permission_scenario($action, PROCESS_KILL_PERMISSION_EPERM);

	expect($out['writes'])->toBe(array())
		->and(implode("\n", $out['log']) . $out['output'])->toContain('another user');
})->with('cleanup routines');

test('cleanup signals owned children but retains a repair worker that stays alive', function ($action) {
	$out = process_kill_permission_scenario($action, 0);

	expect($out['writes'])->toBe($action === 'batchgapfix' ? array() : array('DELETE'))
		->and(implode("\n", $out['log']) . $out['output'])->not->toContain('another user');
})->with('cleanup routines');

test('automation does not start a second network master beside one it may not signal', function () {
	$out = process_kill_permission_scenario('automation', PROCESS_KILL_PERMISSION_EPERM);

	expect($out['writes'])->toBe(array())
		->and(implode("\n", $out['log']))->toContain('another user');
});

test('automation still replaces a network master this user started', function () {
	$out = process_kill_permission_scenario('automation', 0);

	expect($out['writes'])->toBe(array('DELETE', 'DELETE', 'REGISTER'))
		->and(implode("\n", $out['log']))->toContain('is still running for Network ID: 1')
		->and(implode("\n", $out['log']))->not->toContain('another user');
});
