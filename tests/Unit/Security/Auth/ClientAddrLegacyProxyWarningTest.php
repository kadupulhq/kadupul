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
 * lookup now logs one warning per process so the operator can set
 * $trusted_proxies.
 *
 * get_client_addr() is extracted into its own namespace so the once per
 * process state starts clean. The tests run in file order.
 */

namespace ClientAddrLegacyProxyWarningTest;

if (!defined('POLLER_VERBOSITY_DEBUG')) {
	define('POLLER_VERBOSITY_DEBUG', 5);
}

if (!function_exists(__NAMESPACE__ . '\get_client_addr')) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

	preg_match('/^function get_client_addr\(.*?^}\n/ms', $source, $match);

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $match[0]);

	$arrays = file_get_contents(dirname(__DIR__, 4) . '/include/global_arrays.php');
	preg_match('/^\$allowed_proxy_headers\s*=\s*array\(.*?\);/ms', $arrays, $match);

	// the shipped header allowlist, also read from this repository
	eval($match[0]);

	$GLOBALS['allowed_proxy_headers'] = $allowed_proxy_headers;
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {
	if (strpos($message, 'WARNING:') === 0) {
		$GLOBALS['legacy_proxy_warnings'][] = array($message, $environ, $level);
	}
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function legacy_client_addr($proxy_headers) {
	$saved_server  = $_SERVER;
	$saved_config  = isset($GLOBALS['config']) ? $GLOBALS['config'] : null;
	$saved_trusted = isset($GLOBALS['trusted_proxies']) ? $GLOBALS['trusted_proxies'] : null;

	$_SERVER['REMOTE_ADDR']          = '192.0.2.10';
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.66, 203.0.113.9';
	$_SERVER['HTTP_X_REAL_IP']       = '198.51.100.77';

	$GLOBALS['config']['proxy_headers'] = $proxy_headers;
	$GLOBALS['trusted_proxies']         = null;

	try {
		return get_client_addr();
	} finally {
		$_SERVER                    = $saved_server;
		$GLOBALS['config']          = $saved_config;
		$GLOBALS['trusted_proxies'] = $saved_trusted;
	}
}

test('stays quiet when proxy headers are off or outside the allowlist', function () {
	$GLOBALS['legacy_proxy_warnings'] = array();

	expect(legacy_client_addr(null))->toBe('192.0.2.10')
		->and(legacy_client_addr(false))->toBe('192.0.2.10')
		->and(legacy_client_addr(array('REMOTE_ADDR')))->toBe('192.0.2.10')
		->and(legacy_client_addr(array('HTTP_X_REAL_IP')))->toBe('192.0.2.10')
		->and($GLOBALS['legacy_proxy_warnings'])->toBe(array());
});

test('keeps the header address and warns once per process', function () {
	$GLOBALS['legacy_proxy_warnings'] = array();

	expect(legacy_client_addr(array('HTTP_X_FORWARDED_FOR')))->toBe('198.51.100.66')
		->and(legacy_client_addr(array('HTTP_X_FORWARDED_FOR')))->toBe('198.51.100.66')
		->and(legacy_client_addr(true))->toBe('198.51.100.66')
		->and($GLOBALS['legacy_proxy_warnings'])->toHaveCount(1);

	list($message, $environ, $level) = $GLOBALS['legacy_proxy_warnings'][0];

	expect($message)->toContain('$trusted_proxies')
		->and($message)->toContain('any client')
		->and($environ)->toBe('AUTH')
		->and($level)->toBe('');
});
