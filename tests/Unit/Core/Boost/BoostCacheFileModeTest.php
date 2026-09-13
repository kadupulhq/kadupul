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
 * 1.2.31 wrote Boost PNG cache files readable by every local account, so a
 * web server and a CLI poller running as different users could both read
 * them.  The cache keeps its keyed file names and atomic publication.
 */

$root = dirname(__DIR__, 4);

function boostCacheMode_read_config_option($name, $force = false) {
	return $name == 'boost_png_cache_secret' ? str_repeat('ab', 32) : '';
}

function boostCacheMode_get_selected_theme() {
	return 'modern';
}

function boostCacheMode_db_execute_prepared($sql, $params = array()) {
	return true;
}

function boostCacheMode_set_config_option($name, $value) {
}

function boostCacheMode_cacti_log($message) {
	$GLOBALS['boost_cache_mode_logs'][] = $message;
}

function boostCacheModeLoad($root) {
	if (function_exists('boostCacheMode_boost_atomic_write_cache')) {
		return;
	}

	$source = file_get_contents($root . '/lib/boost.php');

	foreach (array('boost_graph_cache_filename', 'boost_atomic_write_cache') as $name) {
		$start = strpos($source, 'function ' . $name . '(');
		$end   = strpos($source, "\nfunction ", $start + 1);

		expect($start)->not->toBeFalse()
			->and($end)->not->toBeFalse();

		eval(preg_replace('/\b(boost_graph_cache_filename|boost_atomic_write_cache|boost_replace_cache_file_on_windows|read_config_option|get_selected_theme|db_execute_prepared|set_config_option|cacti_log)\(/', 'boostCacheMode_$1(', substr($source, $start, $end - $start)));
	}
}

beforeEach(function () use ($root) {
	boostCacheModeLoad($root);

	$GLOBALS['boost_cache_mode_logs'] = array();

	$this->dir   = sys_get_temp_dir() . '/boost-cache-mode-' . bin2hex(random_bytes(4));
	$this->umask = umask(077);
	mkdir($this->dir, 0700);
});

afterEach(function () {
	umask($this->umask);

	foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) as $file) {
		if (is_file($file)) {
			unlink($file);
		}
	}

	rmdir($this->dir);
});

test('a published Boost cache file has mode 0644 under a strict umask', function () {
	if (PHP_OS_FAMILY === 'Windows') {
		$this->markTestSkipped('POSIX file modes only.');
	}

	$file = boostCacheMode_boost_graph_cache_filename($this->dir, 12, 3, 0, array());

	expect(boostCacheMode_boost_atomic_write_cache($file, str_repeat('png', 4096)))->toBeTrue();

	clearstatcache();

	expect(fileperms($file) & 0777)->toBe(0644)
		->and($GLOBALS['boost_cache_mode_logs'])->toBe(array());
});

test('Boost cache file names stay keyed and opaque', function () {
	$file  = boostCacheMode_boost_graph_cache_filename($this->dir, 12, 3, 0, array('graph_width' => 500));
	$thumb = boostCacheMode_boost_graph_cache_filename($this->dir, 12, 3, 0, array('graph_width' => 500, 'graph_nolegend' => true));

	expect(basename($file))->toMatch('/^[a-f0-9]{64}\.png$/')
		->and($file)->not->toBe($thumb)
		->and($file)->not->toContain('_lgi_');
});

test('publication replaces the cache file in one rename and leaves no temporary file', function () {
	$file = $this->dir . '/cache.png';

	file_put_contents($file, 'old');

	expect(boostCacheMode_boost_atomic_write_cache($file, 'new image'))->toBeTrue()
		->and(file_get_contents($file))->toBe('new image')
		->and(glob($this->dir . '/.boost-*'))->toBe(array());
});
