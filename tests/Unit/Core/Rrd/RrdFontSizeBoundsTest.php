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
 * Font sizes are free text in the system and user settings. A thumbnail
 * scaled the title size before checking it, so a blank or non-numeric value
 * threw a TypeError, and a value such as 1e400 passed the check and reached
 * rrdtool as INF, which fails every graph.
 */

require_once dirname(__DIR__, 3) . '/Helpers/RrdGraphHarness.php';

function rrd_font_size_option(string $type, string $size, string $no_legend = '', bool $custom = false) : array {
	$config = array('font_method' => 0);
	$user   = array();

	if ($custom) {
		$user = array('custom_fonts' => 'on', $type . '_font' => 'DejaVu Sans', $type . '_size' => $size);
	} else {
		$config[$type . '_font'] = 'DejaVu Sans';
		$config[$type . '_size'] = $size;
	}

	return cacti_test_rrd_harness_run(array(
		'action'    => 'font',
		'type'      => $type,
		'no_legend' => $no_legend,
		'config'    => $config,
		'user'      => $user,
	));
}

test('legitimate sizes are passed through as before, and thumbnails scale the title', function (string $type, string $size, string $no_legend, string $expected) {
	$result = rrd_font_size_option($type, $size, $no_legend);

	expect($result)->not->toHaveKey('error')
		->and($result['warnings'])->toBe(array())
		->and($result['font'])->toBe('--font ' . strtoupper($type) . ':' . $expected . ":'DejaVu Sans' \\\n");
})->with(array(
	'title'              => array('title', '12', '', '12'),
	'title thumbnail'    => array('title', '10', 'on', '7'),
	'fractional title'   => array('title', '4.5', 'on', '3.15'),
	'legend'             => array('legend', '8', '', '8'),
	'largest title'      => array('title', '100', '', '100'),
));

test('a blank or non-numeric title size on a thumbnail falls back instead of throwing', function (string $size, bool $custom) {
	$result = rrd_font_size_option('title', $size, 'on', $custom);

	expect($result)->not->toHaveKey('error')
		->and($result['font'])->toBe("--font TITLE:8.4:'DejaVu Sans' \\\n");
})->with(array(
	'blank system size'         => array('', false),
	'text system size'          => array('abc', false),
	'blank custom user size'    => array('', true),
	'size at the lower bound'   => array('4', false),
));

test('a non-finite or oversized size falls back to the default', function (string $type, string $size, string $no_legend, string $expected) {
	$result = rrd_font_size_option($type, $size, $no_legend);

	expect($result)->not->toHaveKey('error')
		->and($result['font'])->not->toContain('INF')
		->and($result['font'])->toBe('--font ' . strtoupper($type) . ':' . $expected . ":'DejaVu Sans' \\\n");
})->with(array(
	'infinite title'            => array('title', '1e400', '', '12'),
	'infinite thumbnail title'  => array('title', '1e400', 'on', '8.4'),
	'infinite legend'           => array('legend', '1e400', '', '8'),
	'title above the bound'     => array('title', '101', '', '12'),
	'huge legend'               => array('legend', '5000', '', '8'),
	'huge axis'                 => array('axis', '1000000000', '', '8'),
));

test('rrdtool renders a graph whose font size setting was out of range', function () {
	$dir    = cacti_test_rrdtool_workdir();
	$option = rrd_font_size_option('title', '1e400')['font'];
	$render = cacti_test_rrdtool_graphv_width('/dev/null --start=1700000000 --end=1700030000 --title=x ' . $option, $dir);

	expect($render['error'])->toBe('')
		->and($render['width'])->toBeGreaterThan(0);
})->skip(cacti_test_rrdtool_binary() === '', 'rrdtool is not installed');
