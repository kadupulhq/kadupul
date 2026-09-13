<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

/*
 * rrd_check_path() keeps data source paths inside the RRA directory. It is
 * extracted from lib/rrd.php so the test needs no RRDtool or database.
 */

if (!function_exists('rrd_check_path')) {
	$source = file_get_contents(dirname(__DIR__, 2) . '/lib/rrd.php');
	preg_match('/^function rrd_check_path\(.*?^}\n/ms', $source, $match);
	eval($match[0]);
}

beforeEach(function () {
	$this->base = sys_get_temp_dir() . '/rrd-check-' . bin2hex(random_bytes(4));
	mkdir($this->base . '/sub', 0700, true);
	touch($this->base . '/sub/existing.rrd');
});

test('accepts existing and not-yet-created files under the RRA directory', function () {
	expect(rrd_check_path($this->base . '/sub/existing.rrd', $this->base))->toBeTrue();
	expect(rrd_check_path($this->base . '/sub/new_1.rrd', $this->base))->toBeTrue();
	expect(rrd_check_path($this->base . '/newdir/new_2.rrd', $this->base))->toBeTrue();
});

test('rejects traversal, NUL bytes and empty paths', function () {
	expect(rrd_check_path($this->base . '/../etc/passwd', $this->base))->toBeFalse();
	expect(rrd_check_path('../outside.rrd', $this->base))->toBeFalse();
	expect(rrd_check_path($this->base . "/sub/x.rrd\0.txt", $this->base))->toBeFalse();
	expect(rrd_check_path('', $this->base))->toBeFalse();
});

test('rejects a resolvable path outside the RRA directory', function () {
	$outside = sys_get_temp_dir() . '/rrd-outside-' . bin2hex(random_bytes(4));
	mkdir($outside);

	expect(rrd_check_path($outside . '/x.rrd', $this->base))->toBeFalse();
});

test('does not second-guess an unresolvable RRA base', function () {
	expect(rrd_check_path('./relative/proxy.rrd', '/nonexistent/rra/base'))->toBeTrue();
});
