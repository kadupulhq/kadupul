<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * graph_templates_item has no foreign key to graph_templates_gprint or colors,
 * so a graph item could name a deleted preset and reach rrdtool with an empty
 * GPRINT format, or name a deleted color. rrdtool_graph_item_fallbacks() gives
 * such an item the format that new items default to, or no color as color_id
 * 0 does, and logs the graph once.
 */

require_once __DIR__ . '/../../../Helpers/RrdGraphHarness.php';

/**
 * Runs rrdtool_graph_item_fallbacks() from lib/rrd.php on $items twice for
 * graph 9 in a child process and returns what it logged and the items after.
 */
$runFallbacks = function (array $items) {
	$helper = cacti_test_rrd_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php'), 'rrdtool_graph_item_fallbacks');

	$program = 'function cacti_log($m, $o = false, $e = "") { echo "LOG:" . $e . ":" . $m . "\n"; }'
		. 'function cacti_sizeof($a) { return is_array($a) ? count($a) : 0; }'
		. $helper
		. '$items = json_decode(stream_get_contents(STDIN), true);'
		. 'rrdtool_graph_item_fallbacks($items, 9);'
		. 'rrdtool_graph_item_fallbacks($items, 9);'
		. 'echo "ITEMS:" . json_encode($items) . "\n";';

	$process = proc_open(array(PHP_BINARY, '-r', $program), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	fwrite($pipes[0], json_encode($items));
	fclose($pipes[0]);

	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	expect(proc_close($process))->toBe(0, $stderr);

	return $stdout;
};

test('a graph item whose GPRINT preset was deleted gets the default format, logged once', function () use ($runFallbacks) {
	$stdout = $runFallbacks(array(
		array('gprint_id' => '4', 'gprint_text' => null, 'color_id' => '0', 'hex' => null),
		array('gprint_id' => '2', 'gprint_text' => '%6.2lf', 'color_id' => '0', 'hex' => null),
	));

	expect($stdout)->toContain('"gprint_text":"%8.2lf %s"')
		->and($stdout)->toContain('"gprint_text":"%6.2lf"')
		->and(substr_count($stdout, 'LOG:WEBUI:WARNING: Graph 9 names a deleted GPRINT Preset 4'))->toBe(1);
});

test('a graph item whose color was deleted draws with no color, logged once', function () use ($runFallbacks) {
	$stdout = $runFallbacks(array(
		array('gprint_id' => '0', 'gprint_text' => null, 'color_id' => '7', 'hex' => null),
		array('gprint_id' => '4', 'gprint_text' => null, 'color_id' => '8', 'hex' => null),
	));

	expect($stdout)->toContain('ITEMS:[{"gprint_id":"0","gprint_text":null,"color_id":"7","hex":""},{"gprint_id":"4","gprint_text":"%8.2lf %s","color_id":"8","hex":""}]')
		->and(substr_count($stdout, 'LOG:'))->toBe(1)
		->and($stdout)->toContain('LOG:WEBUI:WARNING: Graph 9 names a deleted Color 7, GPRINT Preset 4, Color 8');
});

test('graph items with valid references or none are left alone and nothing is logged', function () use ($runFallbacks) {
	$items = array(
		array('gprint_id' => '2', 'gprint_text' => '%8.2lf %s', 'color_id' => '5', 'hex' => 'FF0000'),
		array('gprint_id' => '0', 'gprint_text' => null, 'color_id' => '0', 'hex' => null),
		array('gprint_id' => '3', 'gprint_text' => '', 'color_id' => '6', 'hex' => ''),
	);

	$stdout = $runFallbacks($items);

	expect($stdout)->toBe('ITEMS:' . json_encode($items) . "\n");
});

test('the graph query reads the item references and applies the fallbacks before rendering', function () {
	$source = cacti_test_rrd_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php'), 'rrdtool_function_graph');

	$query    = strpos($source, 'gti.gprint_id, gti.color_id,');
	$fallback = strpos($source, 'rrdtool_graph_item_fallbacks($graph_items, $local_graph_id);');
	$render   = strpos($source, '/* +++++++++++++++++++++++ GRAPH ITEMS +++++++++++++++++++++++ */');

	expect($query)->not->toBeFalse()
		->and($fallback)->toBeGreaterThan($query)
		->and($render)->toBeGreaterThan($fallback);
});
