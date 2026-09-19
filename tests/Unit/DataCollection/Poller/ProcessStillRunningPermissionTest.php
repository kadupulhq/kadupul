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
 * cacti_process_still_running() decides whether register_process_start() may
 * replace a registered task. When a cactid poller running as www-data finds
 * the row of a task that was started by root, posix_kill($pid, 0) fails with
 * EPERM. The task was reported as exited, its row was replaced, and a second
 * copy ran.
 *
 * The kernel only returns EPERM across users, so each scenario runs in a child
 * PHP with posix_kill() and posix_get_last_error() disabled and redefined.
 * PHP 8 treats a disabled function as undefined, which lets the child declare
 * its own. The /proc identity check is left real.
 */

require_once dirname(__DIR__, 4) . '/lib/poller.php';

const PROCESS_STILL_RUNNING_EPERM = 1;
const PROCESS_STILL_RUNNING_ESRCH = 3;

/**
 * Run cacti_process_still_running() in a child whose signal probe fails with
 * a chosen errno for one pid.
 *
 * @param int|string $pid   Pid to check, or 'self' for the child's own pid.
 * @param int        $errno Error the stubbed posix_kill() reports for that pid.
 *
 * @return bool The child's cacti_process_still_running() result.
 */
function process_still_running_with_errno($pid, $errno) {
	$library = dirname(__DIR__, 4) . '/lib/poller.php';
	$code    = '$target = ' . var_export($pid, true) . ' === "self" ? getmypid() : ' . var_export($pid, true) . ';'
		. '$stub_errno = 0;'
		. 'function posix_kill($pid, $signal) { global $target, $stub_errno; if ((int) $pid === (int) $target) { $stub_errno = ' . (int) $errno . '; return false; } $stub_errno = 0; return true; }'
		. 'function posix_get_last_error() { global $stub_errno; return $stub_errno; }'
		. 'require ' . var_export($library, true) . ';'
		. 'echo json_encode(cacti_process_still_running($target));';
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

test('a live task owned by another user is still running', function () {
	/* The child checks its own pid, so identity matches and only the EPERM
	   answer from the signal probe decides the result. */
	expect(process_still_running_with_errno('self', PROCESS_STILL_RUNNING_EPERM))->toBeTrue();
});

test('a reserved pid is never reported running', function () {
	/* An unprivileged signal probe of init answers EPERM. Where /proc cannot
	   establish identity, that must not make a stale pid 1 row look live. */
	expect(process_still_running_with_errno(1, PROCESS_STILL_RUNNING_EPERM))->toBeFalse();
});

test('a pid that no longer exists is reported exited', function () {
	expect(process_still_running_with_errno(999999999, PROCESS_STILL_RUNNING_ESRCH))->toBeFalse();
});

test('a live but unrelated process still fails the identity check', function () {
	if (!is_dir('/proc/' . getmypid())) {
		test()->markTestSkipped('command identity is available only on procfs platforms');
	}

	$descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$proc        = proc_open(array('sleep', '5'), $descriptors, $pipes);

	expect($proc)->not->toBeFalse();

	$pid = proc_get_status($proc)['pid'];

	try {
		// proc_open may return before the child has exec'd sleep. Wait for
		// the actual unrelated executable instead of probing its fork state.
		$deadline = microtime(true) + 2;
		do {
			$command = @file_get_contents('/proc/' . $pid . '/cmdline');
			if ($command !== false && strpos($command, "sleep\0") === 0) {
				break;
			}
			usleep(1000);
		} while (microtime(true) < $deadline);
		expect($command)->toStartWith("sleep\0");
		$running = process_still_running_with_errno($pid, PROCESS_STILL_RUNNING_EPERM);
	} finally {
		proc_terminate($proc, 9);
		foreach ($pipes as $pipe) {
			fclose($pipe);
		}
		proc_close($proc);
	}

	expect($running)->toBeFalse();
});
