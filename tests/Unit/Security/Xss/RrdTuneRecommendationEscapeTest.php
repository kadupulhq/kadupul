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
*/

/*
 * rrdtool_tune() prints the differences and the tune and resize
 * recommendations on the Data Source debug view. Those lines start with the
 * RRD file name, which a data source path may set to any text without a line
 * break, so every cell of that table is escaped.
 *
 * rrdtool_tune() declares print_leaves() inside its body, so it runs once in
 * this file.
 */

namespace RrdTuneRecommendationEscapeTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

const CACTI_CLI = false;

// nosemgrep: php.lang.security.eval-use.eval-use -- repository source under test
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php'), 'rrdtool_tune'));

// nosemgrep: php.lang.security.eval-use.eval-use -- repository source under test
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/html.php'), 'html_escape'));

function read_config_option($name) { return $name === 'path_rrdtool' ? '/usr/bin/rrdtool' : ''; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_has_control_chars($value) { return preg_match('/[\x00-\x1F\x7F]/', (string) $value) === 1; }
function __($format, ...$values) { return sprintf($format, ...$values); }
function html_header($columns) { print '<table>'; }
function html_end_box() { print '</table>'; }
function rrdtool_execute(...$args) { throw new \RuntimeException('show_source must not run rrdtool'); }

test('the tune and resize recommendations print the file name as text', function () {
	$name = '/rra/<b>x</b>.rrd';
	$diff = array(
		'ds'     => array('value' => array('heartbeat' => "DS 'value' heartbeat differs in $name")),
		'tune'   => array("$name --heartbeat value:600"),
		'resize' => array("$name 0 GROW 10"),
	);

	ob_start();

	try {
		rrdtool_tune($name, $diff, true);
	} finally {
		$output = ob_get_clean();
	}

	$doc = new \DOMDocument();
	$doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $output . '</body></html>');

	$cells = array();
	foreach ($doc->getElementsByTagName('td') as $cell) {
		$cells[] = $cell->textContent;
	}

	expect($doc->getElementsByTagName('b')->length)->toBe(0)
		->and($cells)->toBe(array(
			"DS 'value' heartbeat differs in $name",
			"/usr/bin/rrdtool tune $name --heartbeat value:600",
			"/usr/bin/rrdtool resize $name 0 GROW 10",
			'rename ' . dirname($name) . '/resize.rrd to ' . $name,
		));
});
