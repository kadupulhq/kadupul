<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
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
 * A running network master handling command == 'cancel' used to signal every
 * worker and then delete every row for the network regardless of whether the
 * signal was delivered. A worker owned by another user answers EPERM, stays
 * running, and lost its row anyway, so the next discovery could start a
 * duplicate worker beside it.
 *
 * The cancel block and clearAllTasks() are pulled directly out of
 * poller_automation.php and evaluated in a child PHP with posix_kill() and
 * posix_get_last_error() disabled and redefined, following the pattern in
 * ProcessKillPermissionTest. The registered pid is the child's own, so the
 * /proc identity check in cacti_process_still_running() matches and only the
 * kill result differs.
 */

const AUTOMATION_CANCEL_EPERM = 1;

/**
 * Run the cancel-handling block against one worker pid whose kill fails with
 * the given errno, then run the real clearAllTasks() with whatever pids the
 * block decided to keep.
 *
 * @param int $errno Errno every signal to the worker pid reports, 0 for a
 *                    signal that is delivered.
 *
 * @return array{log: array<int, string>, deletes: array<int, array{sql: string, params: array}>}
 */
function automation_cancel_scenario($errno) {
	$base = dirname(__DIR__, 4);

	$source = file_get_contents($base . '/poller_automation.php');
	expect($source)->not->toBeFalse('poller_automation.php must be readable');

	expect(preg_match(
		'/\$undead_pids = array\(\);\n\n\t{3}if \(\$command == \x27cancel\x27\) \{\n.*?\n\t{3}\}\n/ms',
		$source,
		$cancel_block
	))->toBe(1, 'the cancel-handling block is no longer where this test expects it');

	expect(preg_match('/^function clearAllTasks\(.*?^}\n/ms', $source, $clear_all_tasks))
		->toBe(1, 'clearAllTasks() is no longer where this test expects it');

	$code = 'define("POLLER_VERBOSITY_MEDIUM", 2);'
		. '$target = getmypid(); $network_id = 1; $command = "cancel";'
		. '$stub_errno = 0; $log = array(); $deletes = array();'
		. 'function posix_kill($pid, $signal) { global $target, $stub_errno; if ((int) $pid === (int) $target) { $stub_errno = ' . (int) $errno . '; return $stub_errno === 0; } $stub_errno = 0; return true; }'
		. 'function posix_get_last_error() { global $stub_errno; return $stub_errno; }'
		. 'function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }'
		. 'function array_rekey($array, $key, $key_value) { $out = array(); foreach ($array as $item) { $out[$item[$key]] = $item[$key_value]; } return $out; }'
		. 'function cacti_log($message, $output = false, $environ = "CMDPHP", $level = 0) { global $log; $log[] = $message; return true; }'
		. 'function db_fetch_assoc_prepared($sql, $params = array()) { global $target; return array(array("pid" => $target)); }'
		. 'function db_execute_prepared($sql, $params = array()) { global $deletes; $deletes[] = array("sql" => trim($sql), "params" => $params); return true; }'
		. 'require ' . var_export($base . '/lib/poller.php', true) . ';'
		. 'eval(' . var_export($cancel_block[0], true) . ');'
		. 'eval(' . var_export($clear_all_tasks[0], true) . ');'
		. 'clearAllTasks($network_id, $undead_pids);'
		. 'echo json_encode(array("log" => $log, "deletes" => $deletes, "target" => $target));';

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

test('a cancel keeps the row of a worker it may not signal', function () {
	$out = automation_cancel_scenario(AUTOMATION_CANCEL_EPERM);

	expect(implode("\n", $out['log']))->toContain('another user')
		->and($out['deletes'])->toHaveCount(1);

	$delete = $out['deletes'][0];

	expect($delete['sql'])->toContain('NOT IN')
		->and($delete['params'])->toBe(array(1, $out['target']));
});

test('a cancel this user started still kills and clears the worker row', function () {
	$out = automation_cancel_scenario(0);

	expect(implode("\n", $out['log']))->not->toContain('another user')
		->and($out['deletes'])->toHaveCount(1);

	$delete = $out['deletes'][0];

	expect($delete['sql'])->not->toContain('NOT IN')
		->and($delete['params'])->toBe(array(1));
});
