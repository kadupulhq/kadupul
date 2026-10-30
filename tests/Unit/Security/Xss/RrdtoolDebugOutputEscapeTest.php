<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The graph debug panels in graph.php, graphs.php and aggregate_graphs.php
 * printed rrdtool_function_graph() straight into the page. With print_source
 * set the function escapes the command it prints itself, but what it returns is
 * raw: RRDtool's stderr, which can quote graph text and paths back, and
 * 'ERROR: RRD file does not exist: <data_source_path>'. Every print of that
 * return value must go through html_escape().
 */

namespace RrdtoolDebugOutputEscapeTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/** @return list<string> each print statement in $file that prints rrdtool_function_graph() */
function graph_prints(string $file) : array {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

	expect($source)->not->toBeFalse();

	$prints    = array();
	$statement = null;

	foreach (token_get_all($source) as $token) {
		$text = is_array($token) ? $token[1] : $token;

		if (is_array($token) && $token[0] === T_PRINT) {
			$statement = '';
		}

		if ($statement === null) {
			continue;
		}

		if ($text === ';' || (is_array($token) && $token[0] === T_CLOSE_TAG)) {
			if (str_contains($statement, 'rrdtool_function_graph(')) {
				$prints[] = preg_replace('/\s+/', ' ', $statement);
			}

			$statement = null;

			continue;
		}

		$statement .= $text;
	}

	return $prints;
}

function run_html_escape(string $call) : string {
	$html = file_get_contents(dirname(__DIR__, 4) . '/lib/html.php');

	expect($html)->not->toBeFalse();

	$code = \test_php_function_source($html, 'html_escape') . ' ' . $call;

	$out    = array();
	$status = 1;
	exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code), $out, $status);

	expect($status)->toBe(0);

	return implode("\n", $out);
}

it('escapes every printed rrdtool_function_graph() result', function (string $file) {
	$prints = graph_prints($file);

	// The command and the "RRDtool Says" output, so a refactor that drops
	// either print cannot pass with nothing to check.
	expect($prints)->toHaveCount(2);

	foreach ($prints as $print) {
		expect($print)->toMatch('/html_escape\(@rrdtool_function_graph\(/');
	}
})->with(array('graph.php', 'graphs.php', 'aggregate_graphs.php'));

it('neutralises markup in the missing RRD error', function () {
	$path = '/var/lib/cacti/rra/<img src=x onerror=alert(1)>.rrd';

	$rendered = run_html_escape('print html_escape(' . var_export('ERROR: RRD file does not exist: ' . $path, true) . ');');

	expect($rendered)->not->toContain('<img');
	expect($rendered)->toContain('&lt;img src=x onerror=alert(1)&gt;.rrd');
});

it('leaves ordinary RRDtool output and the print_source return unchanged', function () {
	expect(run_html_escape('print html_escape("OK u:0.01 s:0.00 r:0.02");'))->toBe('OK u:0.01 s:0.00 r:0.02');
	expect(run_html_escape('var_export(html_escape(null));'))->toBe('NULL');
});
