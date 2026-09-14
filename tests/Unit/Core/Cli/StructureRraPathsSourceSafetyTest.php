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
 * cli/structure_rra_paths.php runs as the main Data Collector's system user,
 * typically root, and moves whatever file the database names as a data
 * source's legacy path into the RRA tree, then chowns the result to match
 * the RRA directory's owner. Neither the move nor the chown followed up on
 * what that legacy path actually was: a symlink planted there let the poller
 * user redirect the chown onto an arbitrary target, and data_sources.php
 * only rejects newlines in data_source_path, so an absolute path could send
 * the whole operation outside the RRA tree entirely.
 *
 * structure_rra_is_safe_source() closes both routes by requiring the source
 * to be a real '.rrd' file that already resolves inside the configured RRA
 * directory. It is extracted here and run against a real temp directory so
 * the symlink and realpath checks are exercised, not just asserted as text.
 */

$source = file_get_contents(dirname(__DIR__, 4) . '/cli/structure_rra_paths.php');

$start = strpos($source, 'function structure_rra_is_safe_source(');
expect($start)->not->toBeFalse();

$end = strpos($source, "\n}\n", $start);
$body = substr($source, $start, $end - $start + 2);

eval($body); // nosemgrep: php.lang.security.eval-use.eval-use

beforeEach(function () {
	$this->base = sys_get_temp_dir() . '/structure_rra_test_' . uniqid();
	mkdir($this->base . '/sub', 0700, true);
});

afterEach(function () {
	foreach (glob($this->base . '/sub/*') as $item) {
		unlink($item);
	}

	rmdir($this->base . '/sub');

	foreach (glob($this->base . '/*') as $item) {
		unlink($item);
	}

	rmdir($this->base);
});

test('a regular .rrd file inside the rra tree is safe to move', function () {
	$path = $this->base . '/sub/ds.rrd';
	file_put_contents($path, 'rrd');

	expect(structure_rra_is_safe_source($path, $this->base))->toBeTrue();
});

test('a symlink at the legacy path is refused', function () {
	$target = $this->base . '/outside.rrd';
	file_put_contents($target, 'rrd');

	$link = $this->base . '/sub/link.rrd';
	symlink($target, $link);

	expect(structure_rra_is_safe_source($link, $this->base))->toBeFalse();
});

test('a dangling symlink at the legacy path is refused', function () {
	$link = $this->base . '/sub/dangling.rrd';
	symlink($this->base . '/does-not-exist.rrd', $link);

	expect(structure_rra_is_safe_source($link, $this->base))->toBeFalse();
});

test('a non-rrd file is refused', function () {
	$path = $this->base . '/sub/notes.txt';
	file_put_contents($path, 'text');

	expect(structure_rra_is_safe_source($path, $this->base))->toBeFalse();
});

test('a path outside the configured rra directory is refused', function () {
	$outside = sys_get_temp_dir() . '/structure_rra_outside_' . uniqid() . '.rrd';
	file_put_contents($outside, 'rrd');

	expect(structure_rra_is_safe_source($outside, $this->base))->toBeFalse();

	unlink($outside);
});

/* PHP caches the last stat() and lstat() result per path, and on 8.3+
   clears that cache on any plain stream read, write or flush.  The swap
   below therefore runs in a child process with no pipes and is waited on
   with proc_get_status() alone, the same as an attacker's own process
   would act, so nothing in this process refreshes the cache before the
   check under test reads it. */
if (!function_exists('structure_rra_swap_externally')) {
	function structure_rra_swap_externally($script) {
		$process = proc_open(array('sh', '-c', $script), array(), $pipes);

		do {
			usleep(10000);
			$status = proc_get_status($process);
		} while ($status['running']);

		return array('process' => $process, 'exit' => $status['exitcode']);
	}
}

test('a legacy file swapped for a symlink after a cached stat is still refused', function () {
	$target = $this->base . '/sub/target.rrd';
	file_put_contents($target, 'rrd');

	$path = $this->base . '/sub/ds.rrd';
	file_put_contents($path, 'rrd');

	is_link($path);

	$swap = structure_rra_swap_externally('rm ' . escapeshellarg($path) . ' && ln -s ' . escapeshellarg($target) . ' ' . escapeshellarg($path));

	$safe = structure_rra_is_safe_source($path, $this->base);

	proc_close($swap['process']);

	expect($swap['exit'])->toBe(0)
		->and($safe)->toBeFalse();
});
