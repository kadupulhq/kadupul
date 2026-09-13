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
*/

/*
 * call_remote_data_collector() must refuse loopback, link-local and other
 * reserved targets before it opens a connection. The function is extracted
 * from lib/functions.php and run with its database and logging helpers
 * stubbed, so no request leaves the test.
 */

if (!function_exists('rdc_test_load')) {
	function rdc_test_load() {
		if (function_exists('call_remote_data_collector')) {
			return;
		}

		$source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
		preg_match('/^function call_remote_data_collector\(.*?^}\n/ms', $source, $match);
		eval($match[0]);
	}

	function db_fetch_cell_prepared($sql, $params = array()) {
		return $GLOBALS['rdc_hostname'];
	}

	function cacti_log($message, $stdout = false, $facility = 'CACTI', $level = '') {
		$GLOBALS['rdc_log'][] = $message;
	}

	function is_ipaddress($address) {
		return filter_var($address, FILTER_VALIDATE_IP) !== false;
	}

	function get_default_contextoption($timeout = false) {
		throw new RuntimeException('connection attempted for a refused address');
	}

	function get_url_type() {
		return 'https';
	}
}

rdc_test_load();

dataset('reserved addresses', ['127.0.0.1', '169.254.169.254', '0.0.0.0', '::1', 'fe80::1']);

test('refuses reserved remote collector addresses before connecting', function ($address) {
	$GLOBALS['rdc_hostname'] = $address;
	$GLOBALS['rdc_log']      = [];

	expect(call_remote_data_collector(2, '/remote_agent.php?action=ping'))->toBe('');
	expect(implode("\n", $GLOBALS['rdc_log']))->toContain('reserved address');
})->with('reserved addresses');

test('lets a private collector address through to the connection step', function () {
	$GLOBALS['rdc_hostname'] = '10.20.30.40';
	$GLOBALS['rdc_log']      = [];

	expect(fn () => call_remote_data_collector(2, '/remote_agent.php?action=ping'))
		->toThrow(RuntimeException::class, 'connection attempted');
});
