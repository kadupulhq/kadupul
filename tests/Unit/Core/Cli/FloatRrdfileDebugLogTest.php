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
 * float_rrdfiles.php --debug opens /tmp/clearer.log for each RRD file. When
 * that log is refused for one file, 1.2.31 kept debugging the next ones. The
 * real float_rrdfile() runs here in a namespace with rrdtool and the log
 * opener stubbed, so no rrdtool binary or database is needed.
 */

namespace FloatRrdfileDebugLogTest;

require_once dirname(__DIR__, 4) . '/lib/maintenance_cli.php';

if (!function_exists(__NAMESPACE__ . '\\float_rrdfile')) {
	$source = \file_get_contents(dirname(__DIR__, 4) . '/cli/float_rrdfiles.php');

	preg_match('/^function float_rrdfile\\(.*?^}\\R/ms', $source, $match);

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $match[0]);
}

function read_config_option($name) {
	return '';
}

function cacti_escapeshellarg($value) {
	return escapeshellarg($value);
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {
	$GLOBALS['float_log'][] = $message;
}

function exec($command, &$output = null, &$result_code = null) {
	$output      = strpos($command, ' dump ') !== false ? $GLOBALS['float_dump'] : array();
	$result_code = 0;

	return '';
}

function cacti_cli_open_log($path) {
	return array_shift($GLOBALS['float_logs']);
}

beforeEach(function () {
	$this->dir = sys_get_temp_dir() . '/cacti_float_debug_' . getmypid() . '_' . mt_rand();

	mkdir($this->dir, 0700);

	$this->rrd = $this->dir . '/traffic.rrd';

	\file_put_contents($this->rrd, 'rrd');

	$GLOBALS['seebug']     = true;
	$GLOBALS['float_log']  = array();
	$GLOBALS['float_dump'] = array(
		'<rrd>',
		'<pdp_per_row>1</pdp_per_row> <!-- 300 seconds -->',
		'<database>',
		'<!-- 2026-01-01 00:15:00 UTC / 900 --> <row><v>1.0</v></row>',
		'<!-- 2026-01-01 00:33:20 UTC / 2000 --> <row><v>2.0</v></row>',
		'</database>',
		'</rrd>',
	);
});

afterEach(function () {
	foreach (glob($this->dir . '/*') ?: array() as $file) {
		unlink($file);
	}

	rmdir($this->dir);
	unset($GLOBALS['seebug']);
});

test('a refused debug log turns debug output off for that file only', function () {
	$log = $this->dir . '/clearer.log';

	$GLOBALS['float_logs'] = array(
		"Refusing to append to '/tmp/clearer.log' because another user owns it",
		fopen($log, 'ab'),
	);

	$first  = float_rrdfile($this->rrd, 'float_debug_a_' . getmypid(), false, 1000, 5000);
	$second = float_rrdfile($this->rrd, 'float_debug_b_' . getmypid(), false, 1000, 5000);

	expect($first)->toBeTrue()
		->and($second)->toBeTrue()
		->and($GLOBALS['seebug'])->toBeTrue()
		->and(implode("\n", $GLOBALS['float_log']))->toContain('Debug output is disabled for this file')
		->and(\file_get_contents($log))->toContain('In Range: CurDate:')
		->and(\file_get_contents($log))->toContain('Not Pruning: CurDate:');
});
