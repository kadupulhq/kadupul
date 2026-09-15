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
 * cli/structure_rra_paths.php runs as root and creates the destination
 * directory for a restructured RRD before moving the file into it. The
 * prior code checked only dirname($new_rrd_path) for a symlink and then
 * called mkdir(..., true), which resolves straight through a symlink
 * planted at an intermediate component, such as a hash-bucket directory,
 * that mkdir() and rename() would both follow silently.
 *
 * structure_rra_prepare_dest_dir() closes that by walking the destination
 * one path component at a time from the canonical RRA root, refusing any
 * existing component that is a symlink or not a directory, and creating
 * missing components non-recursively with an is_link() re-check right
 * after each mkdir(). It is extracted here and run against a real temp
 * directory so the walk is exercised, not just asserted as text.
 */

function structure_rra_dest_test_rrmdir($path) {
	if (is_link($path) || !is_dir($path)) {
		if (file_exists($path) || is_link($path)) {
			unlink($path);
		}

		return;
	}

	foreach (scandir($path) as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}

		structure_rra_dest_test_rrmdir($path . '/' . $entry);
	}

	rmdir($path);
}

$source = file_get_contents(dirname(__DIR__, 4) . '/cli/structure_rra_paths.php');

$start = strpos($source, 'function structure_rra_prepare_dest_dir(');
expect($start)->not->toBeFalse();

$end = strpos($source, "\n}\n", $start);
$body = substr($source, $start, $end - $start + 2);

eval($body); // nosemgrep: php.lang.security.eval-use.eval-use

$dest_start = strpos($source, 'function structure_rra_is_safe_dest(');
expect($dest_start)->not->toBeFalse();

$dest_end  = strpos($source, "\n}\n", $dest_start);
$dest_body = substr($source, $dest_start, $dest_end - $dest_start + 2);

eval($dest_body); // nosemgrep: php.lang.security.eval-use.eval-use

beforeEach(function () {
	$this->base = sys_get_temp_dir() . '/structure_rra_dest_test_' . uniqid();
	mkdir($this->base, 0700, true);
});

afterEach(function () {
	structure_rra_dest_test_rrmdir($this->base);
});

test('missing nested directories inside the rra root are created', function () {
	$dest = $this->base . '/1/2';

	$status = structure_rra_prepare_dest_dir($dest, $this->base);

	expect($status)->toBe('ok')
		->and(is_dir($dest))->toBeTrue()
		->and(is_link($dest))->toBeFalse()
		->and(is_dir($this->base . '/1'))->toBeTrue();
});

test('an already-existing real directory is left alone and reported ok', function () {
	$dest = $this->base . '/1/2';
	mkdir($dest, 0700, true);

	$status = structure_rra_prepare_dest_dir($dest, $this->base);

	expect($status)->toBe('ok')
		->and(is_dir($dest))->toBeTrue();
});

test('a symlinked intermediate directory is refused and nothing is created through it', function () {
	$evil = sys_get_temp_dir() . '/structure_rra_dest_evil_' . uniqid();
	mkdir($evil, 0700, true);

	symlink($evil, $this->base . '/1');

	$dest = $this->base . '/1/2';

	$status = structure_rra_prepare_dest_dir($dest, $this->base);

	expect($status)->toBe('unsafe')
		->and(is_dir($evil . '/2'))->toBeFalse();

	unlink($this->base . '/1');
	rmdir($evil);
});

test('a symlinked final destination is refused', function () {
	$real = $this->base . '/real-target';
	mkdir($real, 0700, true);

	$dest = $this->base . '/1';
	symlink($real, $dest);

	$status = structure_rra_prepare_dest_dir($dest, $this->base);

	expect($status)->toBe('unsafe')
		->and(is_link($dest))->toBeTrue();
});

test('a regular file blocking a missing component fails without being called safe', function () {
	$blocker = $this->base . '/1';
	file_put_contents($blocker, 'not a directory');

	$dest = $blocker . '/2';

	$status = structure_rra_prepare_dest_dir($dest, $this->base);

	expect($status)->toBe('mkdir_failed');
});

test('a destination outside the configured rra directory is refused', function () {
	$dest = sys_get_temp_dir() . '/structure_rra_dest_outside_' . uniqid();

	$status = structure_rra_prepare_dest_dir($dest, $this->base);

	expect($status)->toBe('unsafe')
		->and(is_dir($dest))->toBeFalse();
});

test('a sibling directory sharing the rra directory name as a prefix is refused', function () {
	$sibling = $this->base . '_evil';
	$dest    = $sibling . '/host';

	$status = structure_rra_prepare_dest_dir($dest, $this->base);

	expect($status)->toBe('unsafe')
		->and(is_dir($dest))->toBeFalse()
		->and(is_dir($sibling))->toBeFalse();

	if (is_dir($sibling)) {
		structure_rra_dest_test_rrmdir($sibling);
	}
});

test('a nested destination under an rra directory configured with a trailing slash is accepted', function () {
	$dest = $this->base . '/host';

	$status = structure_rra_prepare_dest_dir($dest, $this->base . '/');

	expect($status)->toBe('ok')
		->and(is_dir($dest))->toBeTrue();
});

/* structure_rra_prepare_dest_dir() above only validates the parent
   directory; structure_rra_is_safe_dest() is the second check the move
   site runs against $new_rrd_path itself right before rename(), so a
   directory or a symlink already sitting at the exact destination is
   refused instead of being moved into or followed. It used to accept
   anything that was merely neither a directory nor a link, which let a
   FIFO, socket or device node through too; it now requires a missing
   path or a regular file. */

test('a missing destination path is safe', function () {
	$dest = $this->base . '/missing.rrd';

	expect(structure_rra_is_safe_dest($dest))->toBeTrue();
});

test('a regular file already at the destination is safe, matching rename()\'s overwrite behavior', function () {
	$dest = $this->base . '/existing.rrd';
	file_put_contents($dest, 'legacy rrd bytes');

	expect(structure_rra_is_safe_dest($dest))->toBeTrue();
});

test('a real directory already at the destination is refused', function () {
	$dest = $this->base . '/existing-dir';
	mkdir($dest, 0700, true);

	expect(structure_rra_is_safe_dest($dest))->toBeFalse();
});

test('a symlink already at the destination is refused', function () {
	$target = $this->base . '/symlink-target';
	file_put_contents($target, 'legacy rrd bytes');

	$dest = $this->base . '/symlinked.rrd';
	symlink($target, $dest);

	expect(structure_rra_is_safe_dest($dest))->toBeFalse();
});

test('a FIFO already at the destination is refused', function () {
	if (!function_exists('posix_mkfifo')) {
		$this->markTestSkipped('posix_mkfifo() is not available');
	}

	$dest = $this->base . '/fifo.rrd';

	expect(posix_mkfifo($dest, 0600))->toBeTrue()
		->and(structure_rra_is_safe_dest($dest))->toBeFalse();
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

test('a destination file swapped for a symlink after a cached stat is still refused', function () {
	$target = $this->base . '/symlink-target';
	file_put_contents($target, 'legacy rrd bytes');

	$dest = $this->base . '/swapped.rrd';
	file_put_contents($dest, 'legacy rrd bytes');

	is_link($dest);

	$swap = structure_rra_swap_externally('rm ' . escapeshellarg($dest) . ' && ln -s ' . escapeshellarg($target) . ' ' . escapeshellarg($dest));

	$safe = structure_rra_is_safe_dest($dest);

	proc_close($swap['process']);

	expect($swap['exit'])->toBe(0)
		->and($safe)->toBeFalse();
});

test('a destination directory swapped for a symlink after the caller\'s cached stat is refused', function () {
	/* the walk compares paths built from realpath() of the rra root, so
	   the root has to be canonical for the caller's cached entry and the
	   walked component to be the same string, as they are in production */
	$base = realpath($this->base);

	$dest = $base . '/1';
	mkdir($dest, 0700);

	$other = $base . '/other';
	mkdir($other, 0700);

	/* the same check the migration loop runs before calling the walk */
	$dest_existed = is_dir($dest) && !is_link($dest);

	$swap = structure_rra_swap_externally('rmdir ' . escapeshellarg($dest) . ' && ln -s ' . escapeshellarg($other) . ' ' . escapeshellarg($dest));

	$status = structure_rra_prepare_dest_dir($dest, $base);

	proc_close($swap['process']);

	expect($dest_existed)->toBeTrue()
		->and($swap['exit'])->toBe(0)
		->and($status)->toBe('unsafe');
});


test('a POSIX root base accepts an existing canonical temporary directory', function () {
    if (DIRECTORY_SEPARATOR !== '/') { $this->markTestSkipped('POSIX root contract'); }
    expect(structure_rra_prepare_dest_dir(realpath(sys_get_temp_dir()), '/'))->toBe('ok');
});
