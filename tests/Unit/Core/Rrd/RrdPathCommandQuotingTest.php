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
 * rrdtool_build_path_command() and rrdtool_execute_restore_command() send a
 * bare path, as 1.2.31 did. rrdtool's command parser reads a quote character
 * in that path as the start of a quoted argument, so info, last, dump and
 * restore failed for a path holding one. RRDproxy runs file_exists, is_dir,
 * mkdir, unlink and archive as PHP functions on arguments split at spaces,
 * with no quote handling, so those must keep the bare path.
 */

require_once dirname(__DIR__, 3) . '/Helpers/RrdGraphHarness.php';

$rrdPathSource = file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php');

test('paths without a quote character build the same command strings as 1.2.31', function () {
	$paths    = array('/var/www/cacti/rra/1/traffic_in_1.rrd', 'C:/cacti/rra/x.rrd', './5/Débit_5.rrd', '/rra/%s-50%.rrd', '/rra/a\\b.rrd');
	$commands = array('file_exists', 'is_dir', 'mkdir', 'unlink', 'archive', 'info', 'last', 'dump');
	$calls    = array();
	$expected = array();

	foreach ($commands as $command) {
		foreach ($paths as $path) {
			$calls[]    = array($command, $path);
			$expected[] = $command . ' ' . $path;
		}
	}

	$result = cacti_test_rrd_harness_run(array('action' => 'path_command', 'commands' => $calls));

	expect($result)->not->toHaveKey('error')
		->and($result['commands'])->toBe($expected);

	$restore = cacti_test_rrd_harness_run(array('action' => 'execute_capture', 'calls' => array(
		array('restore', '/tmp/a_1.rrd.xml', '/var/www/cacti/rra/a_1.rrd'),
		array('raw', 'restore -f /tmp/a_1.rrd.xml /var/www/cacti/rra/a_1.rrd'),
	)));

	expect($restore['written'][0])->toBe($restore['written'][1]);
});

test('RRDproxy PHP commands keep a bare path that the proxy can open', function () {
	$dir  = sys_get_temp_dir() . '/cacti-rrdp-path-' . bin2hex(random_bytes(6));
	$file = $dir . "/it's.rrd";

	mkdir($dir, 0700);
	touch($file);

	$result = cacti_test_rrd_harness_run(array('action' => 'path_command', 'commands' => array(
		array('file_exists', $file),
		array('is_dir', $dir),
	)));

	expect($result['commands'])->toBe(array('file_exists ' . $file, 'is_dir ' . $dir));

	/* Cacti/rrdproxy lib/client.php: $options = explode(' ', $cmd_options); call_user_func_array($cmd, $options) */
	foreach ($result['commands'] as $command) {
		$parts = explode(' ', $command, 2);

		expect(call_user_func_array($parts[0], explode(' ', $parts[1])))->toBeTrue();
	}

	unlink($file);
	rmdir($dir);
});

test('info, last and dump read an RRD path holding an apostrophe', function () {
	$dir = cacti_test_rrdtool_workdir();
	$rrd = $dir . "/it's.rrd";

	copy($dir . '/t.rrd', $rrd);

	$result = cacti_test_rrd_harness_run(array('action' => 'path_command', 'commands' => array(
		array('info', $rrd),
		array('last', $rrd),
		array('dump', $rrd),
	)));

	expect($result)->not->toHaveKey('error');

	$info = cacti_test_rrdtool_batch($result['commands'][0], $dir);
	$last = cacti_test_rrdtool_batch($result['commands'][1], $dir);
	$dump = cacti_test_rrdtool_batch($result['commands'][2], $dir);

	expect($info)->toContain('filename = "' . $rrd . '"')
		->and($last)->not->toContain('ERROR')
		->and($last)->toContain('OK u:')
		->and($dump)->toContain('<rrd>')
		->and($dump)->not->toContain('ERROR');
})->skip(cacti_test_rrdtool_binary() === '', 'rrdtool is not installed');

test('restore writes to and reads from paths holding an apostrophe', function () {
	$dir  = cacti_test_rrdtool_workdir();
	$dump = cacti_test_rrdtool_batch('dump t.rrd', $dir);
	$xml  = $dir . "/o'dump.xml";
	$rrd  = $dir . "/o'restored.rrd";

	file_put_contents($xml, substr($dump, strpos($dump, '<?xml'), strpos($dump, '</rrd>') + 6 - strpos($dump, '<?xml')));

	$result  = cacti_test_rrd_harness_run(array('action' => 'execute_capture', 'calls' => array(array('restore', $xml, $rrd))));
	$command = trim($result['written'][0]);
	$output  = cacti_test_rrdtool_batch($command, $dir);

	expect($output)->not->toContain('ERROR')
		->and(file_exists($rrd))->toBeTrue();
})->skip(cacti_test_rrdtool_binary() === '', 'rrdtool is not installed');

test('a graph renders from an RRD path holding an apostrophe', function () use ($rrdPathSource) {
	$dir = cacti_test_rrdtool_workdir();
	$rrd = $dir . "/it's.rrd";

	copy($dir . '/t.rrd', $rrd);

	expect(cacti_test_rrd_function_source($rrdPathSource, 'rrdtool_function_graph'))
		->toContain("'DEF:' . generate_graph_def_name(strval(\$i)) . '=' . rrdtool_quote_argument(\$data_source_path) . ':' . rrdtool_quote_argument(\$graph_item['data_source_name'])")
		->and(cacti_test_rrd_function_source($rrdPathSource, 'rrdtool_function_graph'))
		->toContain('$data_source_path = rrdtool_escape_string($data_source_path);');

	$exists = cacti_test_rrd_harness_run(array('action' => 'file_exists', 'values' => array($rrd)));
	$def    = cacti_test_rrd_harness_run(array('action' => 'def', 'path' => $rrd));

	expect($exists['exists'])->toBe(array(true));

	$render = cacti_test_rrdtool_graphv_width('/dev/null --start=1700000000 --end=1700030000 ' . $def['def'] . ' LINE1:a#00FF00', $dir);

	expect($render['error'])->toBe('')
		->and($render['width'])->toBeInt();
})->skip(cacti_test_rrdtool_binary() === '', 'rrdtool is not installed');


test('captured command failures release the fixture lease before shutdown', function () {
	$result = cacti_test_rrd_harness_run(array(
		'action' => 'execute_capture',
		'throw_config' => true,
		'calls' => array(array('raw', 'info example.rrd')),
	));
	expect($result['error'])->toBe('RuntimeException: fixture configuration failure')
		->and($result['fixture_cleaned'])->toBeTrue()
		->and($result['warnings'])->toBe(array());
});
