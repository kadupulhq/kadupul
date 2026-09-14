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
