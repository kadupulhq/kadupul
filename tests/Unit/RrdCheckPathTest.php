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
 * rrd_check_path() refuses NUL bytes and '..' segments in data source paths
 * and nothing else, so 1.2.31 custom locations and symlinked storage keep
 * working. It is extracted from lib/rrd.php so the test needs no RRDtool or
 * database.
 */

if (!function_exists('rrd_check_path')) {
	$source = file_get_contents(dirname(__DIR__, 2) . '/lib/rrd.php');
	preg_match('/^function rrd_check_path\(.*?^}\n/ms', $source, $match);
	eval($match[0]);
}

beforeEach(function () {
	$this->root = sys_get_temp_dir() . '/rrd-check-' . bin2hex(random_bytes(4));
	$this->base = $this->root . '/rra';

	mkdir($this->base . '/sub', 0700, true);
	mkdir($this->root . '/outside', 0700);
	touch($this->base . '/sub/existing.rrd');
	touch($this->root . '/outside/x.rrd');
});

afterEach(function () {
	@unlink($this->base . '/linked');
	@unlink($this->base . '/dangling.rrd');
	@unlink($this->base . '/sub/existing.rrd');
	@unlink($this->root . '/outside/x.rrd');
	@rmdir($this->base . '/sub');
	@rmdir($this->base);
	@rmdir($this->root . '/outside');
	@rmdir($this->root);
});

test('refuses empty paths and NUL bytes', function () {
	expect(rrd_check_path('', $this->base))->toBeFalse()
		->and(rrd_check_path(null, $this->base))->toBeFalse()
		->and(rrd_check_path($this->base . "/sub/x.rrd\0.txt", $this->base))->toBeFalse();
});

test('refuses a .. segment at the start, middle or end with any separator', function () {
	expect(rrd_check_path('../outside.rrd', $this->base))->toBeFalse()
		->and(rrd_check_path($this->base . '/../outside/x.rrd', $this->base))->toBeFalse()
		->and(rrd_check_path($this->base . '/sub/..', $this->base))->toBeFalse()
		->and(rrd_check_path('..', $this->base))->toBeFalse()
		->and(rrd_check_path('C:\\cacti\\rra\\..\\x.rrd', $this->base))->toBeFalse()
		->and(rrd_check_path('C:..\\x.rrd', $this->base))->toBeFalse();
});

test('accepts dots that are part of a file or directory name', function () {
	expect(rrd_check_path($this->base . '/host..1.rrd', $this->base))->toBeTrue()
		->and(rrd_check_path($this->base . '/...rrd', $this->base))->toBeTrue()
		->and(rrd_check_path($this->base . '/./sub/existing.rrd', $this->base))->toBeTrue();
});

test('accepts existing and not-yet-created files under the RRA directory', function () {
	expect(rrd_check_path($this->base . '/sub/existing.rrd', $this->base))->toBeTrue()
		->and(rrd_check_path($this->base . '/newdir/new_2.rrd'))->toBeTrue();
});

test('accepts an absolute path outside the RRA directory as 1.2.31 did', function () {
	expect(rrd_check_path($this->root . '/outside/x.rrd', $this->base))->toBeTrue();
});

test('accepts a relative path', function () {
	expect(rrd_check_path('relative/proxy.rrd', $this->base))->toBeTrue()
		->and(rrd_check_path('./relative/proxy.rrd', $this->base))->toBeTrue();
});

test('accepts a symlinked subdirectory that points outside the RRA directory', function () {
	expect(symlink($this->root . '/outside', $this->base . '/linked'))->toBeTrue()
		->and(rrd_check_path($this->base . '/linked/x.rrd', $this->base))->toBeTrue()
		->and(rrd_check_path($this->base . '/linked/new.rrd', $this->base))->toBeTrue();
});

test('accepts a dangling symlink under the RRA directory', function () {
	expect(symlink($this->root . '/outside/missing.rrd', $this->base . '/dangling.rrd'))->toBeTrue()
		->and(file_exists($this->base . '/dangling.rrd'))->toBeFalse()
		->and(rrd_check_path($this->base . '/dangling.rrd', $this->base))->toBeTrue();
});
