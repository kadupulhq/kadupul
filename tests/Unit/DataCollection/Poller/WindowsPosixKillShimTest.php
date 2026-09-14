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
 * The Windows posix_kill() shim in lib/functions.php calls WMI's Terminate()
 * and, for an existing process, used to fall through without returning
 * anything: PHP then reads the missing return as null, which
 * cacti_process_kill() and cacti_process_kill_denied() treat as a failed,
 * denied kill. A same-user process the shim actually terminated therefore
 * kept its registry row forever. These tests run the shim's own source
 * against a stubbed WMI object, so a regression back to the fallthrough is
 * caught here rather than only on a Windows host.
 */

/**
 * Extract the posix_kill() shim body from lib/functions.php.
 *
 * @return string The function declaration, verbatim.
 */
function windows_posix_kill_shim_source() {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

	expect($source)->not->toBeFalse('lib/functions.php must be readable');
	expect(preg_match('/^\tfunction posix_kill\(.*?^\t}\n/ms', $source, $match))->toBe(1, 'the posix_kill() shim was not found');

	return $match[0];
}

/**
 * Run the shim in a child PHP process against a stubbed WMI object.
 *
 * @param array<int, int> $terminate_results One WMI status per matched process,
 *                                            0 for success; an empty array models
 *                                            no process matching the pid.
 * @param int              $signal            Signal passed to posix_kill().
 *
 * @return bool The shim's return value.
 */
function windows_posix_kill_shim_run($terminate_results, $signal) {
	$code = 'foreach (array("SIGTERM" => 15, "SIGKILL" => 9, "SIGHUP" => 1, "SIGINT" => 2) as $name => $value) { if (!defined($name)) { define($name, $value); } }'
		. 'function cacti_sizeof($value) { return is_array($value) || $value instanceof Countable ? count($value) : 0; }'
		. 'function cacti_log($message, $output = false, $environ = "CMDPHP", $level = 0) {}'
		. 'class WindowsPosixKillShimProc { public $results; public $i = 0; function __construct($r) { $this->results = $r; } function Terminate() { return $this->results[$this->i++]; } }'
		. 'class COM implements IteratorAggregate, Countable {'
		. '  private $procs;'
		. '  function __construct($moniker) { global $shim_procs; $this->procs = $shim_procs; }'
		. '  function ExecQuery($query) { return $this; }'
		. '  function getIterator(): Iterator { return new ArrayIterator($this->procs); }'
		. '  function count(): int { return count($this->procs); }'
		. '}'
		. '$shim_procs = array_map(fn($r) => new WindowsPosixKillShimProc(array($r)), ' . var_export($terminate_results, true) . ');'
		. windows_posix_kill_shim_source()
		. 'echo json_encode(array("result" => posix_kill(4242, ' . (int) $signal . ')));';

	$pipes   = array();
	$process = proc_open(array(PHP_BINARY, '-d', 'disable_functions=posix_kill', '-r', $code), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

	expect($process)->not->toBeFalse();

	$stdout = stream_get_contents($pipes[1]);
	$error  = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);

	expect(proc_close($process))->toBe(0, $error);

	$out = json_decode($stdout, true);

	expect($out)->toBeArray($stdout . $error);

	return $out['result'];
}

test('a successful Terminate() on an existing process reports the kill succeeded', function () {
	expect(windows_posix_kill_shim_run(array(0), SIGTERM))->toBeTrue();
});

test('a failed Terminate() on an existing process reports the kill failed', function () {
	expect(windows_posix_kill_shim_run(array(2), SIGTERM))->toBeFalse();
});

test('one of several matched processes failing to terminate reports the kill failed', function () {
	expect(windows_posix_kill_shim_run(array(0, 5), SIGTERM))->toBeFalse();
});

test('a missing process reports the kill failed rather than succeeded', function () {
	expect(windows_posix_kill_shim_run(array(), SIGTERM))->toBeFalse();
});

test('probing an existing process with signal 0 still reports it running', function () {
	expect(windows_posix_kill_shim_run(array(0), 0))->toBeTrue();
});

test('probing a missing process with signal 0 still reports it gone', function () {
	expect(windows_posix_kill_shim_run(array(), 0))->toBeFalse();
});
