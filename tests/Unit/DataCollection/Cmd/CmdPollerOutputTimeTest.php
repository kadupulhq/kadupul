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
 * cmd.php writes each batch to poller_output and, with Boost redirect on, to
 * poller_output_boost in a second statement.  Boost finds the rows it lacks by
 * data source and time, so both copies must carry the same CURRENT_TIMESTAMP()
 * even when the second statement starts in a later second.
 */

$root = dirname(__DIR__, 4);

function cmdOutputTime_read_config_option($name) {
	$options = $GLOBALS['cmd_output_time']['options'];

	return isset($options[$name]) ? $options[$name] : '';
}

/* each statement starts one second after the previous one; SET timestamp pins
 * CURRENT_TIMESTAMP() for the session as MySQL and MariaDB do */
function cmdOutputTime_db_execute($sql, $log = true, $conn = false) {
	$state =& $GLOBALS['cmd_output_time'];
	$state['clock']++;
	$state['statements'][] = preg_replace('/\s+/', ' ', $sql);

	if ($sql === 'SET timestamp = UNIX_TIMESTAMP()') {
		$state['pinned'] = $state['clock'];
	} elseif ($sql === 'SET timestamp = DEFAULT') {
		$state['pinned'] = false;
	} elseif (preg_match('/^INSERT IGNORE INTO (poller_output(?:_boost)?)\s/', $sql, $table)) {
		$now = $state['pinned'] !== false ? $state['pinned'] : $state['clock'];

		preg_match_all("/\((\d+), '([^']*)', CURRENT_TIMESTAMP\(\), '([^']*)'\)/", $sql, $rows, PREG_SET_ORDER);

		foreach ($rows as $row) {
			$state['tables'][$table[1]][] = array((int) $row[1], $row[2], $now);
		}
	}

	return true;
}

function cmdOutputTimeLoad($root) {
	if (function_exists('cmdOutputTime_cmd_write_poller_output')) {
		return;
	}

	$source = file_get_contents($root . '/cmd.php');
	$start  = strpos($source, 'function cmd_write_poller_output(');
	$end    = strpos($source, "\nfunction ", $start + 1);

	expect($start)->not->toBeFalse()
		->and($end)->not->toBeFalse();

	eval(preg_replace('/\b(cmd_write_poller_output|read_config_option|db_execute)\(/', 'cmdOutputTime_$1(', substr($source, $start, $end - $start)));
}

function cmdOutputTimeRun(array $options) {
	$GLOBALS['cmd_output_time'] = array(
		'options'    => $options,
		'clock'      => 1789000000,
		'pinned'     => false,
		'statements' => array(),
		'tables'     => array('poller_output' => array(), 'poller_output_boost' => array()),
	);

	cmdOutputTime_cmd_write_poller_output(array(
		"(7, 'traffic_in', CURRENT_TIMESTAMP(), '10')",
		"(8, 'traffic_in', CURRENT_TIMESTAMP(), '12')",
	), 'poller');

	return $GLOBALS['cmd_output_time'];
}

beforeEach(function () use ($root) {
	cmdOutputTimeLoad($root);
});

test('a flush whose Boost insert starts a second later stores the same time in both tables', function () {
	$state = cmdOutputTimeRun(array('boost_redirect' => 'on', 'boost_rrd_update_enable' => 'on'));

	expect($state['tables']['poller_output'])->toHaveCount(2)
		->and($state['tables']['poller_output_boost'])->toBe($state['tables']['poller_output'])
		->and($state['clock'] - $state['tables']['poller_output'][0][2])->toBeGreaterThanOrEqual(2);
});

test('the session clock is released after the Boost insert', function () {
	$state = cmdOutputTimeRun(array('boost_redirect' => 'on', 'boost_rrd_update_enable' => 'on'));

	expect($state['pinned'])->toBeFalse()
		->and(end($state['statements']))->toBe('SET timestamp = DEFAULT');
});

test('without Boost redirect the flush is the single poller_output insert of 1.2.31', function () {
	$state = cmdOutputTimeRun(array('boost_redirect' => '', 'boost_rrd_update_enable' => 'on'));

	expect($state['statements'])->toHaveCount(1)
		->and($state['statements'][0])->toStartWith('INSERT IGNORE INTO poller_output (')
		->and($state['tables']['poller_output'])->toHaveCount(2)
		->and($state['tables']['poller_output_boost'])->toBe(array());
});

test('cmd.php writes every poller output flush through the one helper', function () use ($root) {
	$source = file_get_contents($root . '/cmd.php');

	expect(substr_count($source, 'cmd_write_poller_output($output_array, $poller_db_cnn_id);'))->toBe(3)
		->and(substr_count($source, 'INSERT IGNORE INTO poller_output_boost'))->toBe(1);
});
