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
 * process_poller_output() writes poller_output rows straight to the RRD files
 * when boost_poller_on_demand() returns true.  With Boost redirect on, cmd.php
 * and spine write every sample to poller_output and poller_output_boost, so a
 * true return here bypasses Boost for the whole cycle.
 */

$root = dirname(__DIR__, 4);

if (!function_exists('boost_error_handler')) {
	function boost_error_handler() {
		return false;
	}
}

function boostRedirect_read_config_option($name) {
	$options = $GLOBALS['boost_redirect_test']['options'];

	return isset($options[$name]) ? $options[$name] : '';
}

function boostRedirect_set_config_option($name, $value) {
}

function boostRedirect_boost_check_correct_enabled() {
	return $GLOBALS['boost_redirect_test']['options']['boost_rrd_update_enable'] == 'on';
}

function boostRedirect_boost_flush_output_batch($value_tuples, $conn = false) {
	$state =& $GLOBALS['boost_redirect_test'];

	$state['staged'] += count($value_tuples);
	$state['tuples']  = array_merge($state['tuples'], $value_tuples);

	/* simulate one chunk landing before a later chunk fails the handoff */
	if ($state['flush_fails'] && count($state['partial_stage'])) {
		$state['boost_rows'] = array_merge($state['boost_rows'], $state['partial_stage']);
	}

	return !$state['flush_fails'];
}

function boostRedirect_db_execute_prepared($sql, $params = array(), $log = true, $conn = false) {
	$GLOBALS['boost_redirect_test']['deletes'][] = $params;

	return !$GLOBALS['boost_redirect_test']['delete_fails'];
}

/* answers the presence lookup from the rows the test places in poller_output_boost */
function boostRedirect_db_fetch_assoc_prepared($sql, $params = array(), $log = true, $conn = false) {
	$state =& $GLOBALS['boost_redirect_test'];
	$state['lookups']++;
	$state['markers'][] = substr_count($sql, '?');

	/* the server refuses a statement with more than 65535 markers */
	if ($state['lookup_fails'] || substr_count($sql, '?') > 65535) {
		return false;
	}

	/* each clause binds one time and then the ids polled at that time */
	preg_match_all('/\(time = \? AND local_data_id IN \(([?,]+)\)\)/', $sql, $clauses);

	$wanted = array();
	$offset = 0;

	foreach ($clauses[1] as $markers) {
		$count = substr_count($markers, '?');
		$time  = $params[$offset];

		for ($i = 1; $i <= $count; $i++) {
			$wanted[$params[$offset + $i] . "\t" . $time] = true;
		}

		$offset += $count + 1;
	}

	$rows = array();

	foreach ($state['boost_rows'] as $row) {
		if (isset($wanted[$row['local_data_id'] . "\t" . $row['time']])) {
			$rows[] = $row;
		}
	}

	$state['read'] += count($rows);

	return $rows;
}

function boostRedirect_boost_validate_poller_ownership($results, $poller_id, $conn = false) {
	return $GLOBALS['boost_redirect_test']['owned'];
}

function boostRedirect_db_qstr($value, $conn = false) {
	return "'" . addslashes($value) . "'";
}

function boostRedirect_cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function boostRedirect_cacti_log($message) {
}

function boostRedirectLoad($root) {
	if (function_exists('boostRedirect_boost_poller_on_demand')) {
		return;
	}

	$source = file_get_contents($root . '/lib/boost.php');

	foreach (array('boost_redirect_missing_rows', 'boost_redirect_delete_staged_rows', 'boost_poller_on_demand') as $name) {
		$start = strpos($source, 'function ' . $name . '(');

		if ($start === false) {
			continue;
		}

		$end = strpos($source, "\nfunction ", $start + 1);

		eval(preg_replace('/\b(boost_redirect_missing_rows|boost_redirect_delete_staged_rows|boost_poller_on_demand|boost_validate_poller_ownership|read_config_option|set_config_option|boost_check_correct_enabled|boost_flush_output_batch|db_fetch_assoc_prepared|db_execute_prepared|db_qstr|cacti_sizeof|cacti_log)\(/', 'boostRedirect_$1(', substr($source, $start, $end - $start)));
	}

	expect(function_exists('boostRedirect_boost_poller_on_demand'))->toBeTrue();
}

function boostRedirectRun(array $options, array $boost_rows = null, array $state = array()) {
	$results = array(
		array('local_data_id' => 7, 'rrd_name' => 'traffic_in', 'time' => '2026-01-01 00:05:00', 'output' => '10'),
		array('local_data_id' => 7, 'rrd_name' => 'traffic_out', 'time' => '2026-01-01 00:05:00', 'output' => '11'),
		array('local_data_id' => 8, 'rrd_name' => 'traffic_in', 'time' => '2026-01-01 00:05:00', 'output' => '12'),
	);

	$GLOBALS['boost_redirect_test'] = $state + array(
		'options'       => $options,
		'staged'        => 0,
		'tuples'        => array(),
		'lookups'       => 0,
		'markers'       => array(),
		'read'          => 0,
		'lookup_fails'  => false,
		'flush_fails'   => false,
		'delete_fails'  => false,
		'deletes'       => array(),
		'partial_stage' => array(),
		'owned'         => true,
		'boost_rows'    => $boost_rows === null ? $results : $boost_rows,
	);

	return boostRedirect_boost_poller_on_demand($results);
}

beforeEach(function () use ($root) {
	boostRedirectLoad($root);

	$this->saved_config = isset($GLOBALS['config']) ? $GLOBALS['config'] : null;
	$GLOBALS['config']  = array('poller_id' => 1, 'connection' => 'online');
});

afterEach(function () {
	$GLOBALS['config'] = $this->saved_config;
});

test('Boost redirect leaves poller output to Boost when Boost already holds every row', function () {
	expect(boostRedirectRun(array('boost_rrd_update_enable' => 'on', 'boost_redirect' => 'on')))->toBeFalse()
		->and($GLOBALS['boost_redirect_test']['staged'])->toBe(0)
		->and($GLOBALS['boost_redirect_test']['lookups'])->toBe(1);
});

test('Boost redirect stages only the rows missing from poller_output_boost', function () {
	$present = array(array('local_data_id' => 7, 'rrd_name' => 'traffic_in', 'time' => '2026-01-01 00:05:00'));

	expect(boostRedirectRun(array('boost_rrd_update_enable' => 'on', 'boost_redirect' => 'on'), $present))->toBeFalse()
		->and($GLOBALS['boost_redirect_test']['lookups'])->toBe(1)
		->and($GLOBALS['boost_redirect_test']['tuples'])->toBe(array(
			"(7,'traffic_out','2026-01-01 00:05:00','11')",
			"(8,'traffic_in','2026-01-01 00:05:00','12')",
		));
});

test('a 40000 row batch with distinct timestamps stays under the marker limit and finds every row', function () {
	$GLOBALS['boost_redirect_test'] = array('lookups' => 0, 'markers' => array(), 'read' => 0, 'lookup_fails' => false, 'boost_rows' => array());

	$results = array();

	for ($i = 1; $i <= 40000; $i++) {
		$results[] = array('local_data_id' => $i, 'rrd_name' => 'traffic_in', 'time' => gmdate('Y-m-d H:i:s', 1789000000 + $i), 'output' => '1');
	}

	$GLOBALS['boost_redirect_test']['boost_rows'] = $results;

	expect(boostRedirect_boost_redirect_missing_rows($results))->toBe(array())
		->and(max($GLOBALS['boost_redirect_test']['markers']))->toBeLessThanOrEqual(60000)
		->and($GLOBALS['boost_redirect_test']['lookups'])->toBe(2);
});

test('the presence lookup reads only the requested data source and time pairs', function () {
	$results = array(
		array('local_data_id' => 7, 'rrd_name' => 'traffic_in', 'time' => '2026-01-01 00:05:00', 'output' => '10'),
		array('local_data_id' => 7, 'rrd_name' => 'traffic_out', 'time' => '2026-01-01 00:05:00', 'output' => '11'),
		array('local_data_id' => 8, 'rrd_name' => 'traffic_in', 'time' => '2026-01-01 00:05:01', 'output' => '12'),
	);

	/* one exact match, and each data source also held at the other one's time */
	$boost_rows = array(
		array('local_data_id' => 7, 'rrd_name' => 'traffic_out', 'time' => '2026-01-01 00:05:00'),
		array('local_data_id' => 7, 'rrd_name' => 'traffic_in', 'time' => '2026-01-01 00:05:01'),
		array('local_data_id' => 8, 'rrd_name' => 'traffic_in', 'time' => '2026-01-01 00:05:00'),
	);

	$GLOBALS['boost_redirect_test'] = array('lookups' => 0, 'markers' => array(), 'read' => 0, 'lookup_fails' => false, 'boost_rows' => $boost_rows);

	expect(boostRedirect_boost_redirect_missing_rows($results))->toBe(array($results[0], $results[2]))
		->and($GLOBALS['boost_redirect_test']['read'])->toBe(1)
		->and($GLOBALS['boost_redirect_test']['lookups'])->toBe(1);
});

test('a failed Boost presence lookup stages every row instead of dropping them', function () {
	expect(boostRedirectRun(array('boost_rrd_update_enable' => 'on', 'boost_redirect' => 'on'), array(), array('lookup_fails' => true)))->toBeFalse()
		->and($GLOBALS['boost_redirect_test']['staged'])->toBe(3);
});

test('when staging the missing rows fails the poller writes the batch directly', function () {
	expect(boostRedirectRun(array('boost_rrd_update_enable' => 'on', 'boost_redirect' => 'on'), array(), array('lookup_fails' => true, 'flush_fails' => true)))->toBeTrue();
});

test('a failed handoff purges the rows already staged before the attempt', function () {
	/* row 0 is already in poller_output_boost; staging the other two fails */
	$present = array(array('local_data_id' => 7, 'rrd_name' => 'traffic_in', 'time' => '2026-01-01 00:05:00'));

	expect(boostRedirectRun(array('boost_rrd_update_enable' => 'on', 'boost_redirect' => 'on'), $present, array('flush_fails' => true)))->toBeTrue()
		->and($GLOBALS['boost_redirect_test']['deletes'])->toBe(array(
			array(7, 'traffic_in', '2026-01-01 00:05:00'),
		));
});

test('a chunk that staged before a later chunk failed is also purged', function () {
	$partial = array(array('local_data_id' => 7, 'rrd_name' => 'traffic_out', 'time' => '2026-01-01 00:05:00'));

	expect(boostRedirectRun(array('boost_rrd_update_enable' => 'on', 'boost_redirect' => 'on'), array(), array('flush_fails' => true, 'partial_stage' => $partial)))->toBeTrue()
		->and($GLOBALS['boost_redirect_test']['deletes'])->toBe(array(
			array(7, 'traffic_out', '2026-01-01 00:05:00'),
		));
});

test('a remote collector still refuses to stage missing rows it does not own', function () {
	$GLOBALS['config']['poller_id'] = 2;

	expect(boostRedirectRun(array('boost_rrd_update_enable' => 'on', 'boost_redirect' => 'on'), array(), array('owned' => false)))->toBeTrue()
		->and($GLOBALS['boost_redirect_test']['staged'])->toBe(0);
});

test('a rejected handoff purges the rows already staged before the attempt', function () {
	$GLOBALS['config']['poller_id'] = 2;

	/* row 0 is already in poller_output_boost; the unowned rows trigger the refusal */
	$present = array(array('local_data_id' => 7, 'rrd_name' => 'traffic_in', 'time' => '2026-01-01 00:05:00'));

	expect(boostRedirectRun(array('boost_rrd_update_enable' => 'on', 'boost_redirect' => 'on'), $present, array('owned' => false)))->toBeTrue()
		->and($GLOBALS['boost_redirect_test']['deletes'])->toBe(array(
			array(7, 'traffic_in', '2026-01-01 00:05:00'),
		));
});

test('without redirect Boost stages the rows and skips the direct update as in 1.2.31', function () {
	expect(boostRedirectRun(array('boost_rrd_update_enable' => 'on', 'boost_redirect' => '')))->toBeFalse()
		->and($GLOBALS['boost_redirect_test']['staged'])->toBe(3)
		->and($GLOBALS['boost_redirect_test']['lookups'])->toBe(0);
});

test('a redirect value other than on stages the rows as in 1.2.31', function () {
	/* cmd.php and spine write poller_output_boost only when boost_redirect is exactly on */
	expect(boostRedirectRun(array('boost_rrd_update_enable' => 'on', 'boost_redirect' => 'yes')))->toBeFalse()
		->and($GLOBALS['boost_redirect_test']['staged'])->toBe(3)
		->and($GLOBALS['boost_redirect_test']['lookups'])->toBe(0);
});

test('with Boost off the poller still updates the RRD files directly', function () {
	expect(boostRedirectRun(array('boost_rrd_update_enable' => '', 'boost_redirect' => 'on')))->toBeTrue()
		->and($GLOBALS['boost_redirect_test']['staged'])->toBe(0);
});
