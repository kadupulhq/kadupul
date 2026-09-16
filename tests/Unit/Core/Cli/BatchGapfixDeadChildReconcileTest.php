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
 * A batchgapfix child that dies before it can stamp its own rows leaves them
 * at ended = "0000-00-00" forever, so the master's wait loop never counts down
 * to zero and its failure exit below the loop is unreachable. The real reaper
 * runs here in a namespace with the process table and the queue stubbed, so no
 * database and no live worker are needed.
 */

namespace BatchGapfixDeadChildReconcileTest;

if (!function_exists(__NAMESPACE__ . '\\batchgapfix_reap_dead_children')) {
	$source = \file_get_contents(dirname(__DIR__, 4) . '/cli/batchgapfix.php');

	preg_match('/^function batchgapfix_reap_dead_children\\(.*?^}\\R/ms', $source, $match);

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $match[0]);
}

function db_fetch_assoc_prepared($sql, $params = array()) {
	$GLOBALS['batchgapfix_selects'][] = array($sql, $params);

	return $GLOBALS['batchgapfix_processes'];
}

function db_execute_prepared($sql, $params = array()) {
	$GLOBALS['batchgapfix_updates'][] = array($sql, $params);

	foreach ($GLOBALS['batchgapfix_rows'] as $index => $row) {
		if ($row['child'] == $params[0] && $row['ended'] === '0000-00-00') {
			$GLOBALS['batchgapfix_rows'][$index]['ended']     = '2026-09-16 12:00:00';
			$GLOBALS['batchgapfix_rows'][$index]['exit_code'] = 1;
		}
	}

	return true;
}

function cacti_process_still_running($pid) {
	return in_array($pid, $GLOBALS['batchgapfix_live_pids'], true);
}

function cacti_process_pid_for_log($pid) {
	return (string) $pid;
}

function unregister_process($tasktype, $taskname, $taskid, $pid = 0) {
	$GLOBALS['batchgapfix_unregistered'][] = array($tasktype, $taskname, $taskid, $pid);
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {
	$GLOBALS['batchgapfix_log'][] = array($message, $environ);
}

function process_row($taskid, $pid) {
	return array('tasktype' => 'batchgapfix', 'taskname' => 'child', 'taskid' => $taskid, 'pid' => $pid);
}

function queue_row($id, $child, $ended = '0000-00-00', $exit_code = 0) {
	return array('id' => $id, 'child' => $child, 'ended' => $ended, 'exit_code' => $exit_code);
}

beforeEach(function () {
	$GLOBALS['batchgapfix_processes']    = array();
	$GLOBALS['batchgapfix_rows']         = array();
	$GLOBALS['batchgapfix_live_pids']    = array();
	$GLOBALS['batchgapfix_selects']      = array();
	$GLOBALS['batchgapfix_updates']      = array();
	$GLOBALS['batchgapfix_unregistered'] = array();
	$GLOBALS['batchgapfix_log']          = array();
});

test('a crashed child has its open rows failed and its process row removed', function () {
	$GLOBALS['batchgapfix_processes'] = array(process_row('1', 4242));
	$GLOBALS['batchgapfix_rows']      = array(
		queue_row(1, 1, '2026-09-16 11:59:00'),
		queue_row(2, 1),
		queue_row(3, 1),
	);

	batchgapfix_reap_dead_children();

	expect(array_column($GLOBALS['batchgapfix_rows'], 'exit_code'))->toBe(array(0, 1, 1))
		->and(array_column($GLOBALS['batchgapfix_rows'], 'ended'))->not->toContain('0000-00-00')
		->and($GLOBALS['batchgapfix_unregistered'])->toBe(array(array('batchgapfix', 'child', '1', 4242)))
		->and($GLOBALS['batchgapfix_log'][0][1])->toBe('SYSTEM')
		->and($GLOBALS['batchgapfix_log'][0][0])->toStartWith('WARNING:')
		->and($GLOBALS['batchgapfix_log'][0][0])->toContain('4242')
		->and(count($GLOBALS['batchgapfix_updates']))->toBe(1)
		->and($GLOBALS['batchgapfix_updates'][0][0])->toContain('ended = "0000-00-00"')
		->and($GLOBALS['batchgapfix_updates'][0][1])->toBe(array('1'));
});

test('a child that is still running keeps its queued rows', function () {
	$GLOBALS['batchgapfix_processes'] = array(process_row('1', 4242));
	$GLOBALS['batchgapfix_live_pids'] = array(4242);
	$GLOBALS['batchgapfix_rows']      = array(queue_row(1, 1));

	batchgapfix_reap_dead_children();

	expect($GLOBALS['batchgapfix_rows'][0]['ended'])->toBe('0000-00-00')
		->and($GLOBALS['batchgapfix_updates'])->toBe(array())
		->and($GLOBALS['batchgapfix_unregistered'])->toBe(array())
		->and($GLOBALS['batchgapfix_log'])->toBe(array());
});

test('only the dead child of a pair is reconciled', function () {
	$GLOBALS['batchgapfix_processes'] = array(process_row('1', 4242), process_row('2', 4343));
	$GLOBALS['batchgapfix_live_pids'] = array(4343);
	$GLOBALS['batchgapfix_rows']      = array(queue_row(1, 1), queue_row(2, 2));

	batchgapfix_reap_dead_children();

	expect(array_column($GLOBALS['batchgapfix_rows'], 'ended'))->toBe(array('2026-09-16 12:00:00', '0000-00-00'))
		->and($GLOBALS['batchgapfix_unregistered'])->toBe(array(array('batchgapfix', 'child', '1', 4242)))
		->and(count($GLOBALS['batchgapfix_updates']))->toBe(1);
});

test('no registered children leaves the queue untouched', function () {
	$GLOBALS['batchgapfix_rows'] = array(queue_row(1, 1));

	batchgapfix_reap_dead_children();

	expect($GLOBALS['batchgapfix_rows'][0]['ended'])->toBe('0000-00-00')
		->and($GLOBALS['batchgapfix_updates'])->toBe(array())
		->and($GLOBALS['batchgapfix_unregistered'])->toBe(array())
		->and($GLOBALS['batchgapfix_log'])->toBe(array())
		->and($GLOBALS['batchgapfix_selects'][0][1])->toBe(array('batchgapfix', 'child'));
});
