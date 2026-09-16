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
 * A float child killed outright never reaches sig_handler(), so nothing
 * removes its row from the processes table and float_processes_running()
 * keeps counting it.  The rmaster's wait loop then never falls to zero, the
 * retained queue is never reported and the CLI never exits.  The real loop and
 * reaper run here in a namespace with the process table, the queue and sleep()
 * stubbed, so no database and no live worker are needed; the stubbed sleep()
 * also caps the loop so a regression fails the test instead of hanging it.
 */

namespace FloatRrdfileDeadChildReapTest;

if (!function_exists(__NAMESPACE__ . '\\float_wait_for_children')) {
	$source = \file_get_contents(\dirname(__DIR__, 4) . '/cli/float_rrdfiles.php');

	\preg_match('/^function float_processes_running\(.*?^\}\R/ms', $source, $running);
	\preg_match('/^function float_reap_dead_children\(.*?^\}\R/ms', $source, $reaper);
	\preg_match('/^\t\$starting = true;\R.*?^\treturn true;\R/ms', $source, $loop);

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $running[0] . $reaper[0]
		. 'function float_wait_for_children() {' . $loop[0] . '}');
}

function sleep($seconds) {
	$GLOBALS['float_sleeps']++;

	if ($GLOBALS['float_sleeps'] > 50) {
		throw new \RuntimeException('the rmaster wait loop did not terminate');
	}

	if ($GLOBALS['float_sleeps'] >= $GLOBALS['float_finish_after']) {
		$GLOBALS['float_processes'] = array();
		$GLOBALS['float_queued']    = '0';
	}

	return 0;
}

function db_fetch_cell($sql) {
	return \strpos($sql, 'FROM processes') !== false
		? (string) \count($GLOBALS['float_processes'])
		: $GLOBALS['float_queued'];
}

function db_fetch_assoc_prepared($sql, $params = array()) {
	$GLOBALS['float_selects'][] = $params;

	return $GLOBALS['float_processes'];
}

function cacti_process_still_running($pid) {
	return \in_array($pid, $GLOBALS['float_live_pids'], true);
}

function cacti_process_pid_for_log($pid) {
	return (string) $pid;
}

function unregister_process($tasktype, $taskname, $taskid, $pid = 0) {
	$GLOBALS['float_unregistered'][] = array($tasktype, $taskname, $taskid, $pid);

	foreach ($GLOBALS['float_processes'] as $index => $row) {
		if ($row['pid'] === $pid) {
			unset($GLOBALS['float_processes'][$index]);
		}
	}

	$GLOBALS['float_processes'] = \array_values($GLOBALS['float_processes']);
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {
	$GLOBALS['float_log'][] = array($message, $environ);
}

function float_debug($message) {
}

function process_row($taskid, $pid) {
	return array('tasktype' => 'rfloat', 'taskname' => 'child', 'taskid' => $taskid, 'pid' => $pid);
}

beforeEach(function () {
	$GLOBALS['float_processes']    = array();
	$GLOBALS['float_live_pids']    = array();
	$GLOBALS['float_queued']       = '0';
	$GLOBALS['float_sleeps']       = 0;
	$GLOBALS['float_finish_after'] = PHP_INT_MAX;
	$GLOBALS['float_selects']      = array();
	$GLOBALS['float_unregistered'] = array();
	$GLOBALS['float_log']          = array();
});

test('a hard-killed child stops the wait and fails the run', function () {
	$GLOBALS['float_processes'] = array(process_row('3', 4242));
	$GLOBALS['float_queued']    = '2';

	expect(float_wait_for_children())->toBeFalse()
		->and($GLOBALS['float_unregistered'])->toBe(array(array('rfloat', 'child', '3', 4242)))
		->and($GLOBALS['float_selects'][0])->toBe(array('rfloat', 'child'))
		->and($GLOBALS['float_log'][0][0])->toStartWith('WARNING:')
		->and($GLOBALS['float_log'][0][0])->toContain('4242')
		->and($GLOBALS['float_log'][1][0])->toStartWith('ERROR:')
		->and($GLOBALS['float_log'][1][1])->toBe('RFLOAT');
});

test('a child still running is left registered and waited on', function () {
	$GLOBALS['float_processes']    = array(process_row('3', 4242));
	$GLOBALS['float_live_pids']    = array(4242);
	$GLOBALS['float_queued']       = '2';
	$GLOBALS['float_finish_after'] = 3;

	expect(float_wait_for_children())->toBeTrue()
		->and($GLOBALS['float_unregistered'])->toBe(array())
		->and($GLOBALS['float_log'])->toBe(array());
});

test('children that are already finished return success without reaping', function () {
	expect(float_wait_for_children())->toBeTrue()
		->and($GLOBALS['float_selects'])->toBe(array())
		->and($GLOBALS['float_unregistered'])->toBe(array())
		->and($GLOBALS['float_log'])->toBe(array());
});

test('rows left behind by a finished run still fail', function () {
	$GLOBALS['float_queued'] = '5';

	expect(float_wait_for_children())->toBeFalse()
		->and($GLOBALS['float_log'][0][0])->toStartWith('ERROR:');
});

test('an unreadable queue count fails the run', function () {
	$GLOBALS['float_queued'] = false;

	expect(float_wait_for_children())->toBeFalse()
		->and($GLOBALS['float_log'][0][0])->toStartWith('ERROR:');
});
