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
 * With System font mode and Custom Fonts on, rrdtool_function_set_font()
 * draws the graph with the viewer's own font settings. The Boost PNG cache
 * name left those settings out, so one user's fonts were served to every
 * other viewer of the graph, and a custom-font user got someone else's image.
 */

$root = dirname(__DIR__, 4);

function boostCacheFont_read_config_option($name, $force = false) {
	if ($name == 'boost_png_cache_secret') {
		return str_repeat('cd', 32);
	}

	return $GLOBALS['boost_cache_font_config'][$name] ?? '';
}

function boostCacheFont_read_user_setting($name, $default = false, $force = false, $user = 0) {
	return $GLOBALS['boost_cache_font_user'][$name] ?? $default;
}

function boostCacheFont_get_selected_theme() {
	return 'modern';
}

function boostCacheFont_db_execute_prepared($sql, $params = array()) {
	return true;
}

function boostCacheFont_set_config_option($name, $value) {
}

function boostCacheFontLoad($root) {
	if (function_exists('boostCacheFont_boost_graph_cache_filename')) {
		return;
	}

	$source = file_get_contents($root . '/lib/boost.php');
	$start  = strpos($source, 'function boost_graph_cache_filename(');
	$end    = strpos($source, "\nfunction ", $start + 1);

	expect($start)->not->toBeFalse()
		->and($end)->not->toBeFalse();

	/* test-only eval of the shipped function, read from this repository */
	eval(preg_replace('/\b(boost_graph_cache_filename|read_config_option|read_user_setting|get_selected_theme|db_execute_prepared|set_config_option)\(/', 'boostCacheFont_$1(', substr($source, $start, $end - $start)));
}

/**
 * @param array<string, string> $config
 * @param array<string, string> $user
 */
function boostCacheFontName(array $config, array $user) : string {
	$GLOBALS['boost_cache_font_config'] = $config;
	$GLOBALS['boost_cache_font_user']   = $user;

	return basename(boostCacheFont_boost_graph_cache_filename('/cache', 12, 3, 0, array('graph_width' => 500)));
}

function boostCacheFontUser(string $legendSize) : array {
	return array(
		'custom_fonts' => 'on',
		'title_font'   => 'DejaVu Sans',
		'title_size'   => '12',
		'legend_font'  => 'DejaVu Sans Mono',
		'legend_size'  => $legendSize,
	);
}

beforeEach(function () use ($root) {
	boostCacheFontLoad($root);
});

test('users with different custom fonts get different cache files', function () {
	$system = array('font_method' => '0');

	$small = boostCacheFontName($system, boostCacheFontUser('8'));
	$large = boostCacheFontName($system, boostCacheFontUser('20'));

	expect($small)->toMatch('/^[a-f0-9]{64}\.png$/')
		->and($small)->not->toBe($large)
		->and(boostCacheFontName($system, boostCacheFontUser('8')))->toBe($small);
});

test('a custom-font user does not share the cache file of users on the system fonts', function () {
	$system = array('font_method' => '0');

	$shared = boostCacheFontName($system, array());
	$custom = boostCacheFontName($system, boostCacheFontUser('8'));

	expect($custom)->not->toBe($shared);
});

test('users without custom fonts keep sharing one cache file', function () {
	$system = array('font_method' => '0');

	$first  = boostCacheFontName($system, array());
	$second = boostCacheFontName($system, array('custom_fonts' => '', 'legend_size' => '20'));

	expect($second)->toBe($first);
});

test('theme font mode ignores user font settings, so the cache file stays shared', function () {
	$theme = array('font_method' => '1');

	expect(boostCacheFontName($theme, boostCacheFontUser('20')))->toBe(boostCacheFontName($theme, array()));
});
