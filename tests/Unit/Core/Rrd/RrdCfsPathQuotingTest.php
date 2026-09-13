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
 * get_rrd_cfs() reads the consolidation functions of an RRD with rrdtool info.
 * It sent the path bare, as 1.2.31 did, so a path holding a quote character
 * opened a quoted argument, rrdtool refused the command and every RRA looked
 * missing. The path is now quoted only when it holds a quote character.
 */

require_once dirname(__DIR__, 3) . '/Helpers/RrdGraphHarness.php';

$cfsFunctionsSource = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

function rrd_cfs_recorder(string $dir) : string {
	$recorder = $dir . '/rrdtool-recorder';

	file_put_contents($recorder, "#!/bin/sh\ncat >> '" . $dir . "/stdin.txt'\n");
	chmod($recorder, 0700);

	return $recorder;
}

test('paths without a quote character send the same info command as 1.2.31', function () {
	$dir   = sys_get_temp_dir() . '/cacti-cfs-' . bin2hex(random_bytes(6));
	mkdir($dir, 0700);

	$paths = array('/var/www/cacti/rra/1/traffic_in_1.rrd', 'C:/cacti/rra/x.rrd', './5/Débit_5.rrd', '/rra/a b.rrd', '/rra/%s.rrd');

	$result = cacti_test_rrd_harness_run(array(
		'action' => 'get_rrd_cfs',
		'values' => $paths,
		'config' => array('path_rrdtool' => rrd_cfs_recorder($dir)),
	));

	$expected = '';

	foreach ($paths as $path) {
		/* 1.2.31 lib/functions.php get_rrd_cfs(): rrdtool_execute("info $rrdfile", ...) */
		$expected .= "info $path\r\nquit\r\n";
	}

	expect($result)->not->toHaveKey('error')
		->and(file_get_contents($dir . '/stdin.txt'))->toBe($expected);

	array_map('unlink', glob($dir . '/*'));
	rmdir($dir);
});

test('consolidation functions are read from an RRD path holding an apostrophe', function () {
	$dir = cacti_test_rrdtool_workdir();

	cacti_test_rrdtool_batch('create cfs.rrd --start 1700000000 --step 300 DS:a:GAUGE:600:U:U RRA:AVERAGE:0.5:1:10 RRA:MAX:0.5:1:10', $dir);
	copy($dir . '/cfs.rrd', $dir . "/it's.rrd");

	$result = cacti_test_rrd_harness_run(array(
		'action' => 'get_rrd_cfs',
		'values' => array($dir . '/cfs.rrd', $dir . "/it's.rrd"),
		'config' => array('path_rrdtool' => cacti_test_rrdtool_binary()),
	));

	expect($result)->not->toHaveKey('error')
		->and($result['cfs'][0])->toBe(array(1 => 1, 3 => 3))
		->and($result['cfs'][1])->toBe($result['cfs'][0]);
})->skip(cacti_test_rrdtool_binary() === '', 'rrdtool is not installed');

test('get_rrd_cfs quotes its info path through the rrdtool path token helper', function () use ($cfsFunctionsSource) {
	$body = cacti_test_rrd_function_source($cfsFunctionsSource, 'get_rrd_cfs');

	expect($body)->toContain("rrdtool_execute('info ' . rrdtool_quote_path_token(\$rrdfile)")
		->and($body)->not->toContain('rrdtool_execute("info $rrdfile"');
});
