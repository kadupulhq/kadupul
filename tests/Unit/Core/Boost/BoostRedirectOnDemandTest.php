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
	$GLOBALS['boost_redirect_test']['staged'] += count($value_tuples);

	return true;
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
	$start  = strpos($source, 'function boost_poller_on_demand(');
	$end    = strpos($source, "\nfunction ", $start + 1);

	expect($start)->not->toBeFalse()
		->and($end)->not->toBeFalse();

	eval(preg_replace('/\b(boost_poller_on_demand|read_config_option|set_config_option|boost_check_correct_enabled|boost_flush_output_batch|db_qstr|cacti_sizeof|cacti_log)\(/', 'boostRedirect_$1(', substr($source, $start, $end - $start)));
}

function boostRedirectRun(array $options) {
	$GLOBALS['boost_redirect_test'] = array('options' => $options, 'staged' => 0);

	$results = array(
		array('local_data_id' => 7, 'rrd_name' => 'traffic_in', 'time' => '2026-01-01 00:05:00', 'output' => '10'),
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

test('Boost redirect leaves poller output to Boost instead of the RRD files', function () {
	expect(boostRedirectRun(array('boost_rrd_update_enable' => 'on', 'boost_redirect' => 'on')))->toBeFalse()
		->and($GLOBALS['boost_redirect_test']['staged'])->toBe(0);
});

test('without redirect Boost stages the rows and skips the direct update', function () {
	expect(boostRedirectRun(array('boost_rrd_update_enable' => 'on', 'boost_redirect' => '')))->toBeFalse()
		->and($GLOBALS['boost_redirect_test']['staged'])->toBe(1);
});

test('with Boost off the poller still updates the RRD files directly', function () {
	expect(boostRedirectRun(array('boost_rrd_update_enable' => '', 'boost_redirect' => 'on')))->toBeTrue()
		->and($GLOBALS['boost_redirect_test']['staged'])->toBe(0);
});
