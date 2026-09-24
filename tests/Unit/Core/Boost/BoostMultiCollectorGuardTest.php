<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * boost_time_to_run() counted data collectors with SELECT COUNT(*) FROM
 * pollers. The table is `poller`; `pollers` exists nowhere in the schema and
 * nowhere else in the codebase. The query therefore always failed, $pollers
 * was never greater than 1, and the guard that keeps boost switched on when
 * more than one collector is defined could not fire, so disabling boost in
 * the UI turned it off on the remote setups that depend on it.
 *
 * The function is extracted and run against a fake settings store and a
 * db_fetch_cell() that answers only the real table name, so a query naming a
 * table that does not exist behaves as it does in production: it returns
 * false rather than a count.
 */

namespace BoostMultiCollectorGuardTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

$source = file_get_contents(dirname(__DIR__, 4) . '/poller_boost.php');

if ($source === false) {
	throw new \RuntimeException('Cannot read poller_boost.php for the collector guard regression.');
}

$GLOBALS['bmc_source'] = $source;

if (!function_exists(__NAMESPACE__ . '\boost_time_to_run')) {
	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($source, 'boost_time_to_run'));
}

/** Only `poller` exists, so any other table name answers as a failed query. */
function db_fetch_cell($sql, $col_name = '', $log = true, $db_conn = false) {
	if (preg_match('/FROM\s+`?(\w+)`?/i', $sql, $match) !== 1) {
		return false;
	}

	if ($match[1] !== 'poller') {
		return false;
	}

	return $GLOBALS['bmc_collectors'];
}

function read_config_option($name, $force = false) {
	return $GLOBALS['bmc_settings'][$name] ?? '';
}

function set_config_option($name, $value, $remote = false) {
	$GLOBALS['bmc_settings'][$name] = $value;
}

function boost_debug($message) {
}

function boost_get_total_rows($max = 0) {
	return 0;
}

function cacti_log($message, $stdout = false, $environ = 'BOOST', $level = 0) {
}

/** Disable boost in the UI with $collectors defined; return the system flag. */
function system_flag_after_disable(int $collectors) : string {
	$GLOBALS['bmc_collectors'] = $collectors;
	$GLOBALS['bmc_settings']   = array(
		'boost_rrd_update_enable'        => '',
		'boost_rrd_update_system_enable' => 'on',
		'boost_rrd_update_interval'      => 120,
		'boost_rrd_update_max_records'   => 1000,
	);

	boost_time_to_run(false, time(), time() - 60, time() + 60);

	return $GLOBALS['bmc_settings']['boost_rrd_update_system_enable'];
}

it('keeps boost on when more than one data collector is defined', function () {
	expect(system_flag_after_disable(2))->toBe('on');
});

it('still turns boost off on a single collector', function () {
	expect(system_flag_after_disable(1))->toBe('');
});

it('counts collectors from a table the schema defines', function () {
	$schema = file_get_contents(dirname(__DIR__, 4) . '/cacti.sql');

	expect($schema)->not->toBeFalse();

	preg_match_all('/FROM\s+`?(\w+)`?\s+WHERE\s+disabled/i', $GLOBALS['bmc_source'], $matches);

	expect($matches[1])->not->toBeEmpty();

	foreach ($matches[1] as $table) {
		expect($schema)->toContain('CREATE TABLE `' . $table . '`');
	}
});
