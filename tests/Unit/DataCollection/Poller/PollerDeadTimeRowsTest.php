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
 * poller.php keeps open poller_time rows so a collector that is still running
 * keeps its tracking (#7024).  1.2.31 cleared every row at cycle start, so a
 * collector that crashed raised the overrun warning and mail once.  A dead
 * collector's row must not raise them again on every later cycle.
 */

$root = dirname(__DIR__, 4);

function pollerDeadTime_db_fetch_assoc_prepared($sql, $params = array(), $log = true, $conn = false) {
	$GLOBALS['poller_dead_time']['selects'][] = array($sql, $params);

	return $GLOBALS['poller_dead_time']['open_rows'];
}

function pollerDeadTime_db_execute_prepared($sql, $params = array(), $log = true, $conn = false) {
	$GLOBALS['poller_dead_time']['deletes'][] = array($sql, $params);

	return true;
}

function pollerDeadTime_cacti_process_signalable($pid) {
	return in_array((int) $pid, $GLOBALS['poller_dead_time']['alive'], true);
}

function pollerDeadTime_has_posix() {
	return $GLOBALS['poller_dead_time']['posix'];
}

function pollerDeadTime_cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function pollerDeadTimeLoad($root) {
	if (function_exists('pollerDeadTime_poller_remove_dead_time_rows')) {
		return;
	}

	$source = file_get_contents($root . '/poller.php');
	$start  = strpos($source, 'function poller_remove_dead_time_rows(');

	expect($start)->not->toBeFalse();

	$end = strpos($source, "\n}\n", $start);

	$function = str_replace("function_exists('posix_kill')", 'pollerDeadTime_has_posix()', substr($source, $start, $end + 3 - $start));

	eval(preg_replace('/\b(poller_remove_dead_time_rows|db_fetch_assoc_prepared|db_execute_prepared|cacti_process_signalable|cacti_sizeof)\(/', 'pollerDeadTime_$1(', $function));
}

beforeEach(function () use ($root) {
	pollerDeadTimeLoad($root);

	$GLOBALS['poller_dead_time'] = array(
		'open_rows' => array(array('pid' => 4101), array('pid' => 4102)),
		'alive'     => array(4101),
		'posix'     => true,
		'selects'   => array(),
		'deletes'   => array(),
	);
});

test('an open row whose collector is gone is removed and a running one is kept', function () {
	pollerDeadTime_poller_remove_dead_time_rows(3, 600);

	expect($GLOBALS['poller_dead_time']['selects'])->toHaveCount(1)
		->and($GLOBALS['poller_dead_time']['selects'][0][1])->toBe(array(3))
		->and($GLOBALS['poller_dead_time']['deletes'])->toHaveCount(1)
		->and($GLOBALS['poller_dead_time']['deletes'][0][1])->toBe(array(3, 4102))
		->and($GLOBALS['poller_dead_time']['deletes'][0][0])->toContain("AND end_time = '0000-00-00 00:00:00'");
});

test('no open rows means no deletes', function () {
	$GLOBALS['poller_dead_time']['open_rows'] = array();

	pollerDeadTime_poller_remove_dead_time_rows(1, 600);

	expect($GLOBALS['poller_dead_time']['deletes'])->toBe(array());
});

test('without posix open rows older than the age limit are removed by age', function () {
	$GLOBALS['poller_dead_time']['posix'] = false;

	pollerDeadTime_poller_remove_dead_time_rows(3, 600);

	expect($GLOBALS['poller_dead_time']['selects'])->toBe(array())
		->and($GLOBALS['poller_dead_time']['deletes'])->toHaveCount(1)
		->and($GLOBALS['poller_dead_time']['deletes'][0][1])->toBe(array(3, 600))
		->and($GLOBALS['poller_dead_time']['deletes'][0][0])->toContain("AND end_time = '0000-00-00 00:00:00'")
		->and($GLOBALS['poller_dead_time']['deletes'][0][0])->toContain('AND start_time < DATE_SUB(NOW(), INTERVAL ? SECOND)');
});

test('the cycle start keeps running rows and then clears dead ones', function () use ($root) {
	$source = file_get_contents($root . '/poller.php');
	$keep   = strpos($source, "AND end_time != '0000-00-00 00:00:00'");
	$clear  = strpos($source, 'poller_remove_dead_time_rows($poller_id, 2 * $cron_interval);');

	expect($keep)->not->toBeFalse()
		->and($clear)->not->toBeFalse()
		->and($clear)->toBeGreaterThan($keep)
		->and($clear)->toBeGreaterThan(strpos($source, 'processes detected as overrunning a polling cycle'));
});
