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
 * float_rrdfile() writes the dumped XML through fwrite() a line at a time and
 * flushes it before handing the name to `rrdtool restore`. A short write or a
 * failed fflush(), as a full filesystem can cause, used to go unchecked and
 * let restore run against truncated XML. The real float_rrdfile() runs here
 * in a namespace with rrdtool, logging and fwrite()/fflush() stubbed, so a
 * short write or failed flush can be simulated without a full disk.
 */

namespace FloatRrdfileShortWriteTest;

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
	$GLOBALS['float_exec_commands'][] = $command;

	$output      = strpos($command, ' dump ') !== false ? $GLOBALS['float_dump'] : array();
	$result_code = 0;

	return '';
}

/* Returns one byte short of $data on the configured call, otherwise writes
 * for real, so the loop's per-line guard can be exercised on any line. */
function fwrite($handle, $data) {
	$GLOBALS['float_fwrite_calls']++;

	if ($GLOBALS['float_fwrite_calls'] === $GLOBALS['float_short_write_at']) {
		return max(0, strlen($data) - 1);
	}

	return \fwrite($handle, $data);
}

/* Fails on demand after every line has written cleanly, so the post-loop
 * guard can be exercised on its own. */
function fflush($handle) {
	if ($GLOBALS['float_fail_flush']) {
		return false;
	}

	return \fflush($handle);
}

beforeEach(function () {
	$this->dir = sys_get_temp_dir() . '/cacti_float_short_' . getmypid() . '_' . mt_rand();

	mkdir($this->dir, 0700);

	$this->rrd = $this->dir . '/traffic.rrd';

	\file_put_contents($this->rrd, 'rrd');

	$GLOBALS['seebug']               = false;
	$GLOBALS['float_log']            = array();
	$GLOBALS['float_exec_commands']  = array();
	$GLOBALS['float_fwrite_calls']   = 0;
	$GLOBALS['float_short_write_at'] = 0;
	$GLOBALS['float_fail_flush']     = false;
	$GLOBALS['float_dump']           = array(
		'<rrd>',
		'<pdp_per_row>1</pdp_per_row> <!-- 300 seconds -->',
		'<database>',
		'<!-- 2026-01-01 00:15:00 UTC / 900 --> <row><v>1.0</v></row>',
		'</database>',
		'</rrd>',
	);
});

afterEach(function () {
	foreach (glob($this->dir . '/*') ?: array() as $file) {
		unlink($file);
	}

	rmdir($this->dir);

	foreach (array('float_short_write_', 'float_flush_fail_') as $prefix) {
		$leftover = sys_get_temp_dir() . '/' . $prefix . getmypid() . '.xml';

		if (file_exists($leftover)) {
			unlink($leftover);
		}
	}

	unset($GLOBALS['seebug']);
});

test('a short fwrite() on the first line is refused before restore', function () {
	$GLOBALS['float_short_write_at'] = 1;

	$local_data_id = 'float_short_write_' . getmypid();
	$tmp_file      = sys_get_temp_dir() . '/' . $local_data_id . '.xml';

	$result = float_rrdfile($this->rrd, $local_data_id, false, 1000, 5000);

	expect($result)->toBeFalse()
		->and(implode("\n", $GLOBALS['float_log']))->toContain('was not written completely')
		->and($GLOBALS['float_exec_commands'])->toHaveCount(1)
		->and(file_exists($tmp_file))->toBeFalse();
});

test('a short fwrite() partway through the dump is refused before restore', function () {
	$GLOBALS['float_short_write_at'] = 3;

	$local_data_id = 'float_short_write_' . getmypid();
	$tmp_file      = sys_get_temp_dir() . '/' . $local_data_id . '.xml';

	$result = float_rrdfile($this->rrd, $local_data_id, false, 1000, 5000);

	expect($result)->toBeFalse()
		->and(implode("\n", $GLOBALS['float_log']))->toContain('was not written completely')
		->and($GLOBALS['float_exec_commands'])->toHaveCount(1)
		->and(file_exists($tmp_file))->toBeFalse();
});

test('a failed fflush() after a clean write is refused before restore', function () {
	$GLOBALS['float_fail_flush'] = true;

	$local_data_id = 'float_flush_fail_' . getmypid();
	$tmp_file      = sys_get_temp_dir() . '/' . $local_data_id . '.xml';

	$result = float_rrdfile($this->rrd, $local_data_id, false, 1000, 5000);

	expect($result)->toBeFalse()
		->and(implode("\n", $GLOBALS['float_log']))->toContain('was not written completely')
		->and($GLOBALS['float_exec_commands'])->toHaveCount(1)
		->and(file_exists($tmp_file))->toBeFalse();
});

test('a clean write and flush proceed to restore', function () {
	$local_data_id = 'float_flush_fail_' . getmypid();
	$tmp_file      = sys_get_temp_dir() . '/' . $local_data_id . '.xml';

	$result = float_rrdfile($this->rrd, $local_data_id, false, 1000, 5000);

	expect($result)->toBeTrue()
		->and($GLOBALS['float_exec_commands'])->toHaveCount(2)
		->and($GLOBALS['float_exec_commands'][1])->toContain('restore')
		->and(file_exists($tmp_file))->toBeFalse();
});
