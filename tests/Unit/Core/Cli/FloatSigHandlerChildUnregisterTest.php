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
 * A float child that is interrupted while waiting on the RRD maintenance lock
 * used to have its row unregistered as the master's ('rfloat', 'rmaster'),
 * which never matches the child's own ('rfloat', 'child', $thread_id) row.
 * float_processes_running() then kept counting the dead child forever, and
 * the rmaster's wait loop never saw it drop to zero. The real sig_handler()
 * calls exit(1), so it is driven here in a subprocess with its dependencies
 * stubbed and no live database; the stubs record what they were called with
 * to a file the parent test reads back.
 */

test('an interrupted float child unregisters its own child row and releases its lock', function ($type, $threadId, $expectKillRunning) {
	$root = dirname(__DIR__, 4);
	$dir  = sys_get_temp_dir() . '/float-sig-child-' . bin2hex(random_bytes(8));

	mkdir($dir, 0700);

	try {
		$source = file_get_contents($root . '/cli/float_rrdfiles.php');

		preg_match('/^function sig_handler\(.*?^}\R/ms', $source, $match);

		expect($match)->not->toBeEmpty();

		$runner = '<?php' . "\n"
			. 'function cacti_log($message, ...$args) {}' . "\n"
			. 'function float_kill_running_processes() { file_put_contents(getenv("OUT") . "/killed-running", "1"); }' . "\n"
			. 'function unregister_process(...$args) { file_put_contents(getenv("OUT") . "/unregistered", json_encode($args)); }' . "\n"
			. 'function rrd_maintenance_release($handle) { file_put_contents(getenv("OUT") . "/released", is_resource($handle) ? "resource" : var_export($handle, true)); }' . "\n"
			. $match[0] . "\n"
			. '$type = ' . var_export($type, true) . ';' . "\n"
			. '$thread_id = ' . var_export($threadId, true) . ';' . "\n"
			. '$rrd_rewrite_lock = fopen(getenv("OUT") . "/lockfile", "c");' . "\n"
			. 'sig_handler(SIGTERM);' . "\n";

		file_put_contents($dir . '/lockfile', '');
		file_put_contents($dir . '/run.php', $runner);

		$process = proc_open(
			array(PHP_BINARY, $dir . '/run.php'),
			array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
			$pipes,
			null,
			array_merge(getenv(), array('OUT' => $dir))
		);

		stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);

		expect($status)->toBe(1)
			->and($stderr)->toBe('')
			->and(file_exists($dir . '/killed-running'))->toBe($expectKillRunning);

		$unregistered = json_decode(file_get_contents($dir . '/unregistered'), true);

		expect($unregistered[0])->toBe('rfloat')
			->and($unregistered[1])->toBe($type)
			->and($unregistered[2])->toBe($threadId)
			->and(file_get_contents($dir . '/released'))->toBe('resource');
	} finally {
		foreach (glob($dir . '/*') ?: array() as $file) {
			unlink($file);
		}

		rmdir($dir);
	}
})->with(array(
	'a child waiting on the maintenance lock' => array('child', 3, false),
	'the rmaster itself'                      => array('rmaster', 0, true),
));
