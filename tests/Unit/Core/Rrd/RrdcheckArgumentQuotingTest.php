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
 * RRDcheck writes info and fetch commands to its own 'rrdtool -' pipe. The
 * arguments go through the same rrdtool parser as graph commands, so they
 * use rrdtool_quote_argument(): unchanged for values without the quote
 * character, and one intact argument for a path holding an apostrophe.
 */

require_once dirname(__DIR__, 3) . '/Helpers/RrdGraphHarness.php';

$rrdcheckSource = file_get_contents(dirname(__DIR__, 4) . '/lib/rrdcheck.php');

test('rrdcheck commands without the quote character are written exactly as cacti_escapeshellarg built them', function () {
	$commands = array(
		array('info', '/var/www/cacti/rra/1/traffic_in_1.rrd'),
		array('fetch', '/var/www/cacti/rra/2/a b.rrd', 'LAST', '-s', 1700000000, '-e', 1700003600),
		array('info', 'C:\\cacti\\rra\\x.rrd'),
		array('fetch', '/rra/Débit "x".rrd', 'LAST', '-s', -86400, '-e', -300),
		'info /var/www/cacti/rra/plain.rrd',
	);

	$result = cacti_test_rrd_harness_run(array('action' => 'rrdcheck_command', 'commands' => $commands));

	expect($result)->not->toHaveKey('error');

	foreach ($commands as $i => $command) {
		if (is_array($command)) {
			$verb   = array_shift($command);
			$legacy = cacti_test_rrd_harness_run(array('action' => 'legacy_quote', 'values' => $command));
			$expect = $verb . ' ' . implode(' ', $legacy['quoted']) . "\r\n";
		} else {
			$expect = $command . "\r\n";
		}

		expect($result['written'][$i])->toBe($expect);
	}
});

test('an RRD path holding an apostrophe reaches rrdtool as one argument', function () {
	$dir = cacti_test_rrdtool_workdir();
	copy($dir . '/t.rrd', $dir . "/it's.rrd");

	$result = cacti_test_rrd_harness_run(array(
		'action'   => 'rrdcheck_rrdtool',
		'rrdtool'  => cacti_test_rrdtool_binary(),
		'cwd'      => $dir,
		'commands' => array(
			array('info', $dir . "/it's.rrd"),
			array('fetch', $dir . "/it's.rrd", 'LAST', '-s', 1700000000, '-e', 1700003600),
		),
	));

	expect($result)->not->toHaveKey('error')
		->and($result['output'][0])->toContain("filename = \"" . $dir . "/it's.rrd\"")
		->and($result['output'][0])->toContain('OK u:')
		->and($result['output'][1])->not->toContain('ERROR')
		->and($result['output'][1])->toContain('OK u:');
})->skip(cacti_test_rrdtool_binary() === '', 'rrdtool is not installed');

test('rrdcheck info and fetch arguments use the rrdtool quoter', function () use ($rrdcheckSource) {
	$body = cacti_test_rrd_function_source($rrdcheckSource, 'rrdcheck_rrdtool_execute');

	expect($body)->toContain('rrdtool_quote_argument($arg)')
		->and($body)->not->toContain('cacti_escapeshellarg(')
		->and($rrdcheckSource)->toContain("rrdtool_execute('info ' . rrdtool_quote_argument(\$file)");
});

test('rrdcheck quotes info for rrdtool and sends file_exists as a bare RRDproxy path command', function () use ($rrdcheckSource) {
	/* rrdtool parses info, but RRDproxy runs file_exists in PHP on arguments split at spaces */
	expect($rrdcheckSource)->toContain("rrdtool_execute('info ' . rrdtool_quote_argument(\$file)")
		->and($rrdcheckSource)->toContain("rrdtool_execute_path_command('file_exists', \$file, '', true, RRDTOOL_OUTPUT_BOOLEAN, false, 'RRDCHECK')")
		->and($rrdcheckSource)->not->toMatch("/'file_exists ' \\. (rrdtool_quote_argument|cacti_escapeshellarg)\\(/");

	$paths  = array('/var/www/cacti/rra/1/traffic_in_1.rrd', './2/a b.rrd', 'C:\\cacti\\rra\\x.rrd');
	$legacy = cacti_test_rrd_harness_run(array('action' => 'legacy_quote', 'values' => $paths));
	$quoted = cacti_test_rrd_harness_run(array('action' => 'quote', 'values' => $paths));

	expect($quoted['quoted'])->toBe($legacy['quoted']);
});
