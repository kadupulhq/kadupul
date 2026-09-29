<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace BoostCollectorCountFailureTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/poller_boost.php'), 'boost_time_to_run'));

function read_config_option($name) {
	return $GLOBALS['boost_collector_config'][$name] ?? '';
}

function set_config_option($name, $value) {
	$GLOBALS['boost_collector_config'][$name] = $value;
}

function boost_debug($message) {
}

function boost_get_total_rows($limit = null) {
	return 0;
}

function db_fetch_cell($sql) {
	$GLOBALS['boost_collector_sql'] = $sql;

	return $GLOBALS['boost_collector_count'];
}

beforeEach(function () {
	$GLOBALS['boost_collector_config'] = array(
		'boost_rrd_update_enable'        => '',
		'boost_rrd_update_system_enable' => 'on',
	);
	$GLOBALS['boost_collector_count'] = false;
	$GLOBALS['boost_collector_sql'] = '';
});

it('preserves the Boost system setting when the active-collector count fails', function () {
	expect(boost_time_to_run(false, 10000, 9000, 0))->toBeFalse();
	expect($GLOBALS['boost_collector_config']['boost_rrd_update_system_enable'])->toBe('on');
	expect($GLOBALS['boost_collector_sql'])->toBe('SELECT COUNT(*) FROM poller WHERE disabled = \'\'');
});

it('still enables system Boost when multiple active collectors are defined', function () {
	$GLOBALS['boost_collector_count'] = '2';
	$GLOBALS['boost_collector_config']['boost_rrd_update_system_enable'] = '';

	expect(boost_time_to_run(false, 10000, 9000, 0))->toBeFalse();
	expect($GLOBALS['boost_collector_config']['boost_rrd_update_system_enable'])->toBe('on');
});
