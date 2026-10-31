<?php
declare(strict_types=1);
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

require_once __DIR__ . '/../../../Helpers/PhpSource.php';

$rankFunctions = file_get_contents(__DIR__ . '/../../../../lib/graph_variables.php');
$countFunctions = file_get_contents(__DIR__ . '/../../../../lib/functions.php');
if ($rankFunctions === false || $countFunctions === false) {
	throw new RuntimeException('Cannot read production percentile functions');
}
eval('namespace PercentileRankRegression; ' .
	test_php_function_source($countFunctions, 'cacti_sizeof') .
	test_php_function_source($rankFunctions, 'cacti_percentile_index') .
	test_php_function_source($rankFunctions, 'cacti_stats_calc'));

// Native RRDtool 1.11.0 PERCENTNAN results for the same 1..N samples.
// The bounded CDEF and CSV XPORT were verified to have identical samples.
// Reference: rrdtool-1.x v1.11.0 src/rrd_graph.c, VDEF_PERCENTNAN.
dataset('native percentile ranks', array(
	'N=1, P=5' => array(1, 5, 1),
	'N=1, P=50' => array(1, 50, 1),
	'N=1, P=95' => array(1, 95, 1),
	'N=1, P=99' => array(1, 99, 1),
	'N=11, P=5' => array(11, 5, 2),
	'N=11, P=50' => array(11, 50, 6),
	'N=11, P=95' => array(11, 95, 11),
	'N=11, P=99' => array(11, 99, 11),
	'N=20, P=5' => array(20, 5, 2),
	'N=20, P=50' => array(20, 50, 11),
	'N=20, P=95' => array(20, 95, 19),
	'N=20, P=99' => array(20, 99, 20),
	'N=8064, P=5' => array(8064, 5, 404),
	'N=8064, P=50' => array(8064, 50, 4033),
	'N=8064, P=95' => array(8064, 95, 7661),
	'N=8064, P=99' => array(8064, 99, 7983),
	'N=8352, P=5' => array(8352, 5, 419),
	'N=8352, P=50' => array(8352, 50, 4177),
	'N=8352, P=95' => array(8352, 95, 7934),
	'N=8352, P=99' => array(8352, 99, 8268),
	'N=8640, P=5' => array(8640, 5, 433),
	'N=8640, P=50' => array(8640, 50, 4321),
	'N=8640, P=95' => array(8640, 95, 8208),
	'N=8640, P=99' => array(8640, 99, 8554),
	'N=8928, P=5' => array(8928, 5, 447),
	'N=8928, P=50' => array(8928, 50, 4465),
	'N=8928, P=95' => array(8928, 95, 8482),
	'N=8928, P=99' => array(8928, 99, 8839),
	'N=11000, P=5' => array(11000, 5, 551),
	'N=11000, P=50' => array(11000, 50, 5501),
	'N=11000, P=95' => array(11000, 95, 10450),
	'N=11000, P=99' => array(11000, 99, 10890),
));

test('statistics match native percentile ranks', function (int $count, int $percentile, int $expected) {
	$stats = \PercentileRankRegression\cacti_stats_calc(range(1, $count), $percentile);
	expect($stats['p' . $percentile . 'n'])->toEqual($expected);
	expect($stats['elements'])->toBe($count);
	expect($stats['sum'])->toEqual($count * ($count + 1) / 2);
})->with('native percentile ranks');

test('empty percentile statistics retain zero output', function () {
	$stats = \PercentileRankRegression\cacti_stats_calc(array(), 5);
	expect($stats['p5n'])->toBe(0);
	expect($stats['p95n'])->toBe(0);
	expect($stats['elements'])->toBe(0);
});

test('zero-valued percentile statistics retain observed samples', function () {
	$stats = \PercentileRankRegression\cacti_stats_calc(array(0, 0, 0), 5);
	expect($stats['p5n'])->toBe(0);
	expect($stats['p95n'])->toBe(0);
	expect($stats['elements'])->toBe(3);
});
