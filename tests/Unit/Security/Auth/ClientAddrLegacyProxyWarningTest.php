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
 * Without $trusted_proxies, get_client_addr() keeps the 1.2.31 handling:
 * any client can set a header named in $proxy_headers and choose the
 * address remote_agent.php authorizes. The address stays the same, but the
 * lookup logs a warning at most once a day, tracked in the settings table
 * through debounce_run_notification(), so php-fpm workers do not flood
 * cacti.log. A lookup before the database is connected skips the warning.
 *
 * Each case loads a fresh copy of get_client_addr() so its once per
 * process gate starts clean, as a new worker would. The settings table and
 * the clock are stubbed.
 */

namespace ClientAddrLegacyProxyWarningTest;

if (!defined('POLLER_VERBOSITY_DEBUG')) {
	define('POLLER_VERBOSITY_DEBUG', 5);
}

if (!function_exists(__NAMESPACE__ . '\debounce_run_notification')) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

	preg_match('/^function debounce_run_notification\(.*?^}\n/ms', $source, $match);

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $match[0]);

	$arrays = file_get_contents(dirname(__DIR__, 4) . '/include/global_arrays.php');
	preg_match('/^\$allowed_proxy_headers\s*=\s*array\(.*?\);/ms', $arrays, $match);

	// the shipped header allowlist, also read from this repository
	eval($match[0]);

	$GLOBALS['allowed_proxy_headers'] = $allowed_proxy_headers;
}

/* returns the name of a freshly loaded copy of get_client_addr() */
function fresh_get_client_addr() {
	static $copies = 0;

	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');
	preg_match('/^function get_client_addr\(.*?^}\n/ms', $source, $match);

	$name = 'get_client_addr_' . ++$copies;

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . str_replace('function get_client_addr(', 'function ' . $name . '(', $match[0]));

	return __NAMESPACE__ . '\\' . $name;
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {
	if (strpos($message, 'WARNING:') === 0) {
		$GLOBALS['legacy_proxy_warnings'][] = array($message, $environ, $level);
	}
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function read_config_option($name) {
	return isset($GLOBALS['legacy_proxy_settings'][$name]) ? $GLOBALS['legacy_proxy_settings'][$name] : '';
}

function set_config_option($name, $value) {
	$GLOBALS['legacy_proxy_settings'][$name] = $value;
}

function time() {
	return $GLOBALS['legacy_proxy_now'];
}

function legacy_client_addr($lookup, $proxy_headers, $connected = true) {
	$saved_server = $_SERVER;
	$saved_config = isset($GLOBALS['config']) ? $GLOBALS['config'] : null;
	$saved_names  = array();

	foreach (array('trusted_proxies', 'database_sessions', 'database_hostname', 'database_port', 'database_default') as $name) {
		$saved_names[$name] = isset($GLOBALS[$name]) ? $GLOBALS[$name] : null;
	}

	$_SERVER['REMOTE_ADDR']          = '192.0.2.10';
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.66, 203.0.113.9';
	$_SERVER['HTTP_X_REAL_IP']       = '198.51.100.77';

	$GLOBALS['config']['proxy_headers'] = $proxy_headers;
	$GLOBALS['trusted_proxies']         = null;
	$GLOBALS['database_hostname']       = 'db';
	$GLOBALS['database_port']           = '3306';
	$GLOBALS['database_default']        = 'kadupul';
	$GLOBALS['database_sessions']       = $connected ? array('db:3306:kadupul' => true) : array();

	try {
		return $lookup();
	} finally {
		$_SERVER           = $saved_server;
		$GLOBALS['config'] = $saved_config;

		foreach ($saved_names as $name => $value) {
			$GLOBALS[$name] = $value;
		}
	}
}

function start_day() {
	$GLOBALS['legacy_proxy_settings'] = array();
	$GLOBALS['legacy_proxy_warnings'] = array();
	$GLOBALS['legacy_proxy_now']      = 1767225600;
}

test('stays quiet when proxy headers are off or outside the allowlist', function () {
	start_day();
	$lookup = fresh_get_client_addr();

	expect(legacy_client_addr($lookup, null))->toBe('192.0.2.10')
		->and(legacy_client_addr($lookup, false))->toBe('192.0.2.10')
		->and(legacy_client_addr($lookup, array('REMOTE_ADDR')))->toBe('192.0.2.10')
		->and(legacy_client_addr($lookup, array('HTTP_X_REAL_IP')))->toBe('192.0.2.10')
		->and($GLOBALS['legacy_proxy_warnings'])->toBe(array());
});

test('keeps the header address and warns once', function () {
	start_day();
	$lookup = fresh_get_client_addr();

	expect(legacy_client_addr($lookup, array('HTTP_X_FORWARDED_FOR')))->toBe('198.51.100.66')
		->and(legacy_client_addr($lookup, array('HTTP_X_FORWARDED_FOR')))->toBe('198.51.100.66')
		->and(legacy_client_addr($lookup, true))->toBe('198.51.100.66')
		->and($GLOBALS['legacy_proxy_warnings'])->toHaveCount(1);

	list($message, $environ, $level) = $GLOBALS['legacy_proxy_warnings'][0];

	expect($message)->toContain('$trusted_proxies')
		->and($message)->toContain('any client')
		->and($environ)->toBe('AUTH')
		->and($level)->toBe('');
});

test('other processes stay quiet within a day and warn again after it', function () {
	start_day();

	legacy_client_addr(fresh_get_client_addr(), array('HTTP_X_FORWARDED_FOR'));
	expect($GLOBALS['legacy_proxy_warnings'])->toHaveCount(1);

	$GLOBALS['legacy_proxy_now'] += 86399;

	expect(legacy_client_addr(fresh_get_client_addr(), array('HTTP_X_FORWARDED_FOR')))->toBe('198.51.100.66')
		->and($GLOBALS['legacy_proxy_warnings'])->toHaveCount(1);

	$GLOBALS['legacy_proxy_now'] += 2;

	expect(legacy_client_addr(fresh_get_client_addr(), array('HTTP_X_FORWARDED_FOR')))->toBe('198.51.100.66')
		->and($GLOBALS['legacy_proxy_warnings'])->toHaveCount(2);
});

test('skips the warning before the database is connected', function () {
	start_day();

	expect(legacy_client_addr(fresh_get_client_addr(), array('HTTP_X_FORWARDED_FOR'), false))->toBe('198.51.100.66')
		->and($GLOBALS['legacy_proxy_warnings'])->toBe(array())
		->and($GLOBALS['legacy_proxy_settings'])->toBe(array());
});
