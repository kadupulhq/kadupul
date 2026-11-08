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
 * Opening the Data Source info view compares the RRD file with the Data
 * Source definition.  The comparison reports differences; it never writes the
 * stored heartbeat, and a resize recommendation names the archive inside the
 * RRD file.
 */

namespace RrdCompareReadOnly;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php'), 'rrdtool_cacti_compare'));

function __($text, ...$args) { return vsprintf($text, $args); }
function cacti_sizeof($value) { return is_array($value) || $value instanceof \Countable ? count($value) : 0; }
function rrdtool_function_info_from_ds($data_source_id) { return $GLOBALS['rc_cacti']; }
function db_fetch_cell_prepared(...$args) { $GLOBALS['rc_writes'][] = array('read', $args[0]); return 600; }
function db_execute_prepared(...$args) { $GLOBALS['rc_writes'][] = array('write', $args[0]); return true; }
function db_execute(...$args) { $GLOBALS['rc_writes'][] = array('write', $args[0]); return true; }
function cacti_log(...$args) { $GLOBALS['rc_logs'][] = $args[0]; }

function rc_ds($heartbeat) {
	return array('type' => 'GAUGE', 'minimal_heartbeat' => $heartbeat, 'min' => '0', 'max' => 'U');
}

beforeEach(function () {
	$GLOBALS['rc_writes'] = array();
	$GLOBALS['rc_logs']   = array();
});

test('a stored heartbeat that differs from the profile is reported and never rewritten', function () {
	$GLOBALS['rc_cacti'] = array('step' => 300, 'ds' => array('traffic' => rc_ds(900)), 'rra' => array());
	$info                = array('filename' => '/rra/a.rrd', 'step' => 300, 'ds' => array('traffic' => rc_ds(600)), 'rra' => array());

	$diff = rrdtool_cacti_compare(1, $info);

	expect($GLOBALS['rc_writes'])->toBe(array());
	expect($GLOBALS['rc_logs'])->toBe(array());
	expect($diff['tune'])->toBe(array('/rra/a.rrd --heartbeat traffic:900'));
});

test('resize recommendations use the archive index of the file, not the profile position', function () {
	// The profile lists the steps-1 archive first; the file was created with it second.
	$GLOBALS['rc_cacti'] = array('step' => 300, 'ds' => array(), 'rra' => array(
		0 => array('cf' => 'AVERAGE', 'steps' => 1, 'rows' => 50, 'xff' => 0.5),
		1 => array('cf' => 'AVERAGE', 'steps' => 100, 'rows' => 70, 'xff' => 0.5),
	));
	$info = array('filename' => '/rra/a.rrd', 'step' => 300, 'ds' => array(), 'rra' => array(
		0 => array('cf' => 'AVERAGE', 'pdp_per_row' => 100, 'rows' => 60, 'xff' => 0.5),
		1 => array('cf' => 'AVERAGE', 'pdp_per_row' => 1, 'rows' => 100, 'xff' => 0.5),
	));

	$diff = rrdtool_cacti_compare(1, $info);

	expect($diff['resize'])->toBe(array('/rra/a.rrd 1 SHRINK 50', '/rra/a.rrd 0 GROW 10'));
});
