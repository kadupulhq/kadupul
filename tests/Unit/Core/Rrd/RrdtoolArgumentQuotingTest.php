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
 * Graph, xport and fetch commands are written to 'rrdtool -'. That parser
 * splits on spaces, groups with either quote character and has no escape
 * character, so escapeshellarg()'s '\'' idiom closes the argument early.
 * Values without the quote character must still be quoted exactly as
 * cacti_escapeshellarg() quoted them in 1.2.31.
 */

require_once dirname(__DIR__, 3) . '/Helpers/RrdGraphHarness.php';

$rrdSource = file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php');

$legacyValues = array(
	'Traffic In', 'bits per second', 'DejaVu Sans Mono', '/var/www/cacti/rra/1/a_1.rrd',
	'a,b,+', '%8.2lf %s', '-86400', 1700000000, 0, '0', '', 'C\\:/rra', 'Débit réseau',
	"line\nfeed", "carriage\rreturn", 'tab	here', 'end\\', '--width=1234', 'x --width=1234 DEF:z=/etc/passwd:a:AVERAGE',
);

$roundTripValues = array(
	'plain'                    => 'Traffic In',
	'apostrophe'               => "it's",
	'apostrophe breakout'      => "t' --width=1234 --title='pwned",
	'double quotes'            => 'say "hi"',
	'mixed quotes'             => "a'b\"c'd\"e",
	'backslash'                => 'C:\\rra\\x.rrd',
	'trailing backslash'       => 'end\\',
	'backslash before quote'   => "x\\' --height=9 '",
	'option inside value'      => 'x --width=1234 DEF:z=/etc/passwd:a:AVERAGE',
	'tab'                      => "tab\there",
	'percent'                  => 'load %s %%',
	'utf-8'                    => 'Débit réseau',
);

test('values without the quote character are quoted exactly as cacti_escapeshellarg quotes them', function (string $os, array $extra) use ($legacyValues) {
	$values = array_merge($legacyValues, $extra);
	$legacy = cacti_test_rrd_harness_run(array('action' => 'legacy_quote', 'os' => $os, 'values' => $values));
	$quoted = cacti_test_rrd_harness_run(array('action' => 'quote', 'os' => $os, 'values' => $values));

	expect($legacy)->not->toHaveKey('error')
		->and($quoted)->not->toHaveKey('error')
		->and($quoted['quoted'])->toBe($legacy['quoted']);
})->with(array(
	'unix'  => array('unix', array('say "hi"')),
	'win32' => array('win32', array("it's")),
));

test('the quote character is written as a group rrdtool reads back literally', function () {
	$unix  = cacti_test_rrd_harness_run(array('action' => 'quote', 'os' => 'unix', 'values' => array("it's", "a\nb'\r\0c")));
	$win32 = cacti_test_rrd_harness_run(array('action' => 'quote', 'os' => 'win32', 'values' => array('say "hi"')));

	expect($unix['quoted'])->toBe(array("'it'\"'\"'s'", "'ab'\"'\"'c'"))
		->and($win32['quoted'])->toBe(array("\"say \"'\"'\"hi\"'\"'\"\""));
});

test('a NUL byte is dropped instead of failing the command', function () {
	$result = cacti_test_rrd_harness_run(array('action' => 'quote', 'values' => array("a\0b")));

	expect($result)->not->toHaveKey('error')
		->and($result['quoted'])->toBe(array("'ab'"));
});

test('rrdtool reads every quoted value back as one unchanged argument', function () use ($roundTripValues) {
	$dir    = cacti_test_rrdtool_workdir();
	$values = array_values($roundTripValues);
	$lines  = array();

	foreach (array('unix', 'win32') as $os) {
		$result = cacti_test_rrd_harness_run(array('action' => 'quote', 'os' => $os, 'values' => $values));

		foreach ($result['quoted'] as $quoted) {
			$lines[] = 'info ' . $quoted;
		}
	}

	$output = explode("\n", trim(cacti_test_rrdtool_batch(implode("\n", $lines), $dir)));

	foreach (array_merge($values, $values) as $i => $value) {
		expect($output[$i])->toBe("ERROR: opening '" . $value . "': No such file or directory");
	}
})->skip(cacti_test_rrdtool_binary() === '', 'rrdtool is not installed');

test('a newline in a value cannot start a second rrdtool command', function () {
	$dir    = cacti_test_rrdtool_workdir();
	$result = cacti_test_rrd_harness_run(array('action' => 'quote', 'values' => array("x'\ncreate pwned.rrd DS:a:GAUGE:600:U:U RRA:AVERAGE:0.5:1:1")));
	$output = cacti_test_rrdtool_batch('info ' . $result['quoted'][0], $dir);

	expect($output)->toStartWith("ERROR: opening 'x'create pwned.rrd DS:a:GAUGE:600:U:U RRA:AVERAGE:0.5:1:1'")
		->and(file_exists($dir . '/pwned.rrd'))->toBeFalse();
})->skip(cacti_test_rrdtool_binary() === '', 'rrdtool is not installed');

test('escapeshellarg quoting lets an apostrophe inject a graph option (why the quoter exists)', function () {
	$dir    = cacti_test_rrdtool_workdir();
	$result = cacti_test_rrd_harness_run(array('action' => 'legacy_quote', 'values' => array('x', "t' --width=1234 --title='pwned")));
	$plain  = cacti_test_rrdtool_graphv_width('/dev/null --start=1700000000 --end=1700030000 --title=' . $result['quoted'][0], $dir);
	$evil   = cacti_test_rrdtool_graphv_width('/dev/null --start=1700000000 --end=1700030000 --title=' . $result['quoted'][1], $dir);

	expect($evil['width'])->not->toBe($plain['width']);
})->skip(cacti_test_rrdtool_binary() === '', 'rrdtool is not installed');

test('a device string in a graph option cannot inject rrdtool options', function () {
	$dir   = cacti_test_rrdtool_workdir();
	$graph = array('image_format_id' => 1, 'right_axis' => '1:0', 'right_axis_label' => '|host_description|', 'host_id' => 1);
	$base  = array('action' => 'graph_options', 'start' => 1700000000, 'end' => 1700030000, 'graph' => $graph, 'config' => array('font_method' => 1), 'graph_data_array' => array('output_filename' => '/dev/null'));

	$plain = cacti_test_rrd_harness_run($base + array('substitutions' => array('|host_description|' => 'eth0')));
	$evil  = cacti_test_rrd_harness_run($base + array('substitutions' => array('|host_description|' => "eth0' --width=1234 --title='pwned")));

	expect($plain)->not->toHaveKey('error')
		->and($evil)->not->toHaveKey('error');

	$plainRender = cacti_test_rrdtool_graphv_width($plain['options'], $dir);
	$evilRender  = cacti_test_rrdtool_graphv_width($evil['options'], $dir);

	expect($plainRender['error'])->toBe('')
		->and($evilRender['error'])->toBe('')
		->and($evilRender['width'])->toBe($plainRender['width']);
})->skip(cacti_test_rrdtool_binary() === '', 'rrdtool is not installed');

test('a user font setting cannot inject rrdtool options and legitimate fonts are unchanged', function () {
	$dir  = cacti_test_rrdtool_workdir();
	$font = function (string $value) {
		return cacti_test_rrd_harness_run(array(
			'action'    => 'font',
			'type'      => 'title',
			'config'    => array('font_method' => 0),
			'user'      => array('custom_fonts' => 'on', 'title_font' => $value, 'title_size' => '12'),
		));
	};

	expect($font('DejaVu Sans Mono')['font'])->toBe("--font TITLE:12:'DejaVu Sans Mono' \\\n");

	$plainRender = cacti_test_rrdtool_graphv_width('/dev/null --start=1700000000 --end=1700030000 ' . $font('Sans')['font'], $dir);
	$evilRender  = cacti_test_rrdtool_graphv_width('/dev/null --start=1700000000 --end=1700030000 ' . $font("Sans' --width=1234 --title='pwned")['font'], $dir);

	expect($plainRender['error'])->toBe('')
		->and($evilRender['error'])->toBe('')
		->and($evilRender['width'])->toBe($plainRender['width']);
})->skip(cacti_test_rrdtool_binary() === '', 'rrdtool is not installed');

test('graph, xport and fetch arguments use the rrdtool quoter, not shell quoting', function () use ($rrdSource) {
	foreach (array('rrd_function_process_graph_options', 'rrdtool_function_graph', 'rrdtool_function_fetch', 'rrdtool_function_set_font') as $name) {
		$body = cacti_test_rrd_function_source($rrdSource, $name);

		expect($body)->not->toContain('cacti_escapeshellarg(')
			->and($body)->toContain('rrdtool_quote_argument(');
	}

	expect(cacti_test_rrd_function_source($rrdSource, '__rrd_execute'))->toContain("array_map('rrdtool_quote_argument', \$command_line)");
});
