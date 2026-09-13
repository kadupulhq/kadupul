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
 * branch of register_process_start() registered a second copy beside it.
 *
 * Each scenario runs in a child PHP with posix_kill() and
 * posix_get_last_error() disabled and redefined, as in
 * ProcessStillRunningPermissionTest, and with the database and log stubbed as
 * in PollerSystemPidBranchesTest. The registered pid is the child's own, so
 * the /proc identity check matches and only the signal result differs.
 */

const PROCESS_KILL_PERMISSION_EPERM = 1;
const PROCESS_KILL_PERMISSION_ESRCH = 3;

/**
 * Run one timeout or cleanup path against a registered row whose signals fail
 * with a chosen errno.
 *
 * @param string     $action 'register', 'timeout' or 'dsstats'.
 * @param int        $errno  Error every signal to the registered pid reports,
 *                           or 0 for a signal that is delivered.
 * @param int|string $pid    Registered pid, or 'self' for the child's own pid.
 *
 * @return array{result: mixed, log: array<int, string>, writes: array}
 */
function process_kill_permission_scenario($action, $errno, $pid = 'self') {
	$base    = dirname(__DIR__, 4);
	$invoke  = array(
		'register' => '$result = register_process_start("poller", "test", 0, 300);',
		'timeout'  => '$result = timeout_kill_registered_processes();',
		'dsstats'  => '$type = ""; require ' . var_export($base . '/lib/dsstats.php', true) . '; $result = dsstats_kill_running_processes();',
	);
	$code    = 'define("POLLER_VERBOSITY_MEDIUM", 2);'
		. '$target = ' . var_export($pid, true) . ' === "self" ? getmypid() : ' . var_export($pid, true) . ';'
		. '$stub_errno = 0; $log = array(); $writes = array();'
		. '$row = array("tasktype" => "poller", "taskname" => "child", "taskid" => 0, "pid" => $target, "timeout" => 300, "timeout_exceeded" => 1720000000, "current_timestamp" => 1720000600);'
		. 'function posix_kill($pid, $signal) { global $target, $stub_errno; if ((int) $pid === (int) $target) { $stub_errno = ' . (int) $errno . '; return $stub_errno === 0; } $stub_errno = 0; return true; }'
		. 'function posix_get_last_error() { global $stub_errno; return $stub_errno; }'
		. 'function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }'
		. 'function cacti_log($message, $output = false, $environ = "CMDPHP", $level = 0) { global $log; $log[] = $message; return true; }'
		. 'function db_table_exists($table) { return true; }'
		. 'function db_execute_prepared($sql, $params = array()) { global $writes; $writes[] = trim(strtok(trim($sql), " ")); return true; }'
		. 'function db_fetch_row_prepared($sql, $params = array()) { global $row; return $row; }'
		. 'function db_fetch_assoc_prepared($sql, $params = array()) { global $row; return array($row); }'
		. 'require ' . var_export($base . '/lib/poller.php', true) . ';'
		. $invoke[$action]
		. 'echo json_encode(array("result" => $result, "log" => $log, "writes" => $writes));';
	$pipes   = array();
	$process = proc_open(array(PHP_BINARY, '-d', 'disable_functions=posix_kill,posix_get_last_error', '-r', $code), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

	expect($process)->not->toBeFalse();

	$output = stream_get_contents($pipes[1]);
	$error  = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);

	expect(proc_close($process))->toBe(0, $error);

	return json_decode($output, true);
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

test('the timeout sweep keeps the row of a task it may not signal', function () {
	$out = process_kill_permission_scenario('timeout', PROCESS_KILL_PERMISSION_EPERM);

	expect($out['writes'])->toBe(array())
		->and(implode("\n", $out['log']))->toContain('another user');
});

test('the timeout sweep still kills and retires a task this user started', function () {
	$out = process_kill_permission_scenario('timeout', 0);

	expect($out['writes'])->toBe(array('DELETE'))
		->and(implode("\n", $out['log']))->toContain('Process killed due to timeout!')
		->and(implode("\n", $out['log']))->not->toContain('another user');
});

test('the timeout sweep retires the row of a task that is already gone', function () {
	$out = process_kill_permission_scenario('timeout', PROCESS_KILL_PERMISSION_ESRCH, 999999999);

	expect($out['writes'])->toBe(array('DELETE'))
		->and(implode("\n", $out['log']))->toContain('Detected process that is gone and did not unregister first!');
});

test('dsstats cleanup keeps the row of a child it may not signal', function () {
	$denied    = process_kill_permission_scenario('dsstats', PROCESS_KILL_PERMISSION_EPERM);
	$delivered = process_kill_permission_scenario('dsstats', 0);

	expect($denied['writes'])->toBe(array())
		->and(implode("\n", $denied['log']))->toContain('another user')
		->and($delivered['writes'])->toBe(array('DELETE'));
});

test('script cleanup sites check for a denied signal before retiring a row', function () {
	/* These loops live in scripts that run on include, so they are checked in
	   source. Each one has the same shape as dsstats_kill_running_processes(). */
	foreach (array('cli/batchgapfix.php', 'cli/float_rrdfiles.php', 'cli/rebuild_poller_cache.php', 'lib/rrdcheck.php', 'poller_commands.php') as $file) {
		$src = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

		expect($src)->not->toBeFalse($file . ' must be readable');

		$kill   = strpos($src, 'if (!cacti_process_kill(');
		$denied = strpos($src, 'cacti_process_kill_denied(');
		$retire = strpos($src, 'unregister_process(', (int) $kill);

		expect($kill)->not->toBeFalse($file)
			->and($denied)->toBeGreaterThan($kill)
			->and($retire)->toBeGreaterThan($denied);
	}
});
