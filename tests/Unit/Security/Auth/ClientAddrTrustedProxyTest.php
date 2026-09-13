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
 * $trusted_proxies is opt-in. Without it, get_client_addr() and the session
 * cookie Secure flag must behave exactly as in 1.2.31, including taking the
 * first valid entry of a header without trimming it. With it, forwarded
 * headers count only from a listed peer, and the address is the right-most
 * hop that is not itself a trusted proxy. remote_agent.php authorizes
 * collectors on this address.
 *
 * The functions and the global.php block are extracted into this namespace
 * with logging stubbed; other unit tests load the same global names.
 */

namespace ClientAddrTrustedProxyTest;

if (!defined('POLLER_VERBOSITY_DEBUG')) {
	define('POLLER_VERBOSITY_DEBUG', 5);
}

if (!function_exists(__NAMESPACE__ . '\get_client_addr')) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');
	$code   = '';

	foreach (array('get_client_addr', 'get_trusted_client_addr', 'is_trusted_proxy_addr') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\n/ms', $source, $match)) {
			$code .= $match[0];
		}
	}

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $code);

	$arrays = file_get_contents(dirname(__DIR__, 4) . '/include/global_arrays.php');
	preg_match('/^\$allowed_proxy_headers\s*=\s*array\(.*?\);/ms', $arrays, $match);

	// the shipped header allowlist, also read from this repository
	eval($match[0]);

	$GLOBALS['allowed_proxy_headers'] = $allowed_proxy_headers;
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function with_request(array $server, $trusted_proxies, callable $run) {
	$saved_server  = $_SERVER;
	$saved_config  = isset($GLOBALS['config']) ? $GLOBALS['config'] : null;
	$saved_trusted = isset($GLOBALS['trusted_proxies']) ? $GLOBALS['trusted_proxies'] : null;

	foreach (array_keys($_SERVER) as $key) {
		if ($key == 'REMOTE_ADDR' || $key == 'HTTPS' || strpos($key, 'HTTP_') === 0) {
			unset($_SERVER[$key]);
		}
	}

	$_SERVER = array_merge($_SERVER, $server);

	$GLOBALS['trusted_proxies'] = $trusted_proxies;

	try {
		return $run();
	} finally {
		$_SERVER                    = $saved_server;
		$GLOBALS['config']          = $saved_config;
		$GLOBALS['trusted_proxies'] = $saved_trusted;
	}
}

function resolve_client_addr(array $server, $proxy_headers, $trusted_proxies = null) {
	return with_request($server, $trusted_proxies, function () use ($proxy_headers) {
		$GLOBALS['config']['proxy_headers'] = $proxy_headers;

		return get_client_addr();
	});
}

function forwarded_https(array $server, $proxy_headers, $trusted_proxies = null) {
	static $block;

	if ($block === null) {
		$source = file_get_contents(dirname(__DIR__, 4) . '/include/global.php');
		preg_match('/^\t\$https = \(!empty\(\$_SERVER\[\'HTTPS\'\]\).*?(?=^\tif \(\$https\) \{)/ms', $source, $match);

		$block = $match[0];
	}

	return with_request($server, $trusted_proxies, function () use ($block, $proxy_headers) {
		$config = array('proxy_headers' => $proxy_headers);

		// test-only eval of a block read from this repository, not external input
		eval('namespace ' . __NAMESPACE__ . '; ' . $block);

		return $https;
	});
}

/* 1.2.31 behaviour, expected with $trusted_proxies unset or empty */
dataset('legacy client addresses', array(
	'default configuration' => array(
		array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.66'), null, '192.0.2.10'),
	'named header, left-most entry' => array(
		array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.66, 203.0.113.9'), array('HTTP_X_FORWARDED_FOR'), '198.51.100.66'),
	'untrimmed entries are skipped' => array(
		array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => 'bogus, 203.0.113.9'), array('HTTP_X_FORWARDED_FOR'), '192.0.2.10'),
	'first valid entry without a space' => array(
		array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => 'bogus,203.0.113.9'), array('HTTP_X_FORWARDED_FOR'), '203.0.113.9'),
	'all headers in allowlist order' => array(
		array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_CLIENT_IP' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.2'), true, '198.51.100.2'),
	'dash-style names never match' => array(
		array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.66'), array('X-Forwarded-For'), '192.0.2.10'),
	'headers outside the allowlist are dropped' => array(
		array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_REAL_IP' => '198.51.100.66'), array('HTTP_X_REAL_IP'), '192.0.2.10'),
	'invalid REMOTE_ADDR' => array(
		array('REMOTE_ADDR' => 'not-an-address'), null, false),
	'no REMOTE_ADDR' => array(
		array(), null, false),
));

test('keeps 1.2.31 client address selection without trusted proxies', function ($server, $proxy_headers, $expected) {
	expect(resolve_client_addr($server, $proxy_headers))->toBe($expected);
	expect(resolve_client_addr($server, $proxy_headers, array()))->toBe($expected);
})->with('legacy client addresses');

test('keeps 1.2.31 cookie Secure handling without trusted proxies', function () {
	$forwarded = array('REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_SSL' => 'on');

	expect(forwarded_https($forwarded, array('HTTP_X_FORWARDED_FOR')))->toBeFalse()
		->and(forwarded_https($forwarded, true, array()))->toBeFalse()
		->and(forwarded_https(array('REMOTE_ADDR' => '10.0.0.1', 'HTTPS' => 'on'), null))->toBeTrue()
		->and(forwarded_https(array('REMOTE_ADDR' => '10.0.0.1', 'HTTPS' => 'off'), null))->toBeFalse();
});

/*
 * release/1.2.31 include/global.php set the Secure flag from direct TLS only;
 * the forwarded-proto block arrived later on lts/1.2 (070476015).
 */
function release_1_2_31_https() {
	return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off');
}

dataset('cookie secure inputs', function () {
	$cases = array();

	foreach (array(null, '', 'on', 'off', '1') as $https) {
		foreach (array(array(), array('HTTP_X_FORWARDED_PROTO' => 'https'), array('HTTP_X_FORWARDED_SSL' => 'on')) as $forwarded) {
			foreach (array(null, array(), true, array('HTTP_X_FORWARDED_FOR')) as $proxy_headers) {
				foreach (array(null, array()) as $trusted) {
					$server = array('REMOTE_ADDR' => '10.0.0.1') + $forwarded;

					if ($https !== null) {
						$server['HTTPS'] = $https;
					}

					$cases[] = array($server, $proxy_headers, $trusted);
				}
			}
		}
	}

	return $cases;
});

test('matches the release/1.2.31 cookie Secure condition without trusted proxies', function ($server, $proxy_headers, $trusted) {
	$expected = with_request($server, null, fn () => release_1_2_31_https());

	expect(forwarded_https($server, $proxy_headers, $trusted))->toBe($expected);
})->with('cookie secure inputs');

test('ignores forwarded headers from a peer outside the trusted list', function () {
	expect(resolve_client_addr(array(
		'REMOTE_ADDR'          => '192.0.2.10',
		'HTTP_X_FORWARDED_FOR' => '198.51.100.66',
	), array('HTTP_X_FORWARDED_FOR'), array('10.0.0.1')))->toBe('192.0.2.10');
});

test('takes the right-most untrusted hop, not the client-supplied left-most one', function () {
	expect(resolve_client_addr(array(
		'REMOTE_ADDR'          => '10.0.0.1',
		'HTTP_X_FORWARDED_FOR' => '198.51.100.66, 203.0.113.9',
	), array('HTTP_X_FORWARDED_FOR'), array('10.0.0.1')))->toBe('203.0.113.9');
});

test('skips intermediate hops inside a trusted CIDR range', function () {
	expect(resolve_client_addr(array(
		'REMOTE_ADDR'          => '10.0.0.1',
		'HTTP_X_FORWARDED_FOR' => '198.51.100.66, 203.0.113.9, 10.0.0.7',
	), array('HTTP_X_FORWARDED_FOR'), array('10.0.0.0/24')))->toBe('203.0.113.9');
});

test('returns the left-most hop when every hop is trusted', function () {
	expect(resolve_client_addr(array(
		'REMOTE_ADDR'          => '10.0.0.1',
		'HTTP_X_FORWARDED_FOR' => '10.0.0.3, 10.0.0.2',
	), array('HTTP_X_FORWARDED_FOR'), array('10.0.0.0/24')))->toBe('10.0.0.3');
});

test('does not trust a peer outside the CIDR mask', function () {
	expect(resolve_client_addr(array(
		'REMOTE_ADDR'          => '10.0.0.200',
		'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
	), array('HTTP_X_FORWARDED_FOR'), array('10.0.0.0/25')))->toBe('10.0.0.200');
});

test('matches IPv6 trusted proxy ranges', function () {
	expect(resolve_client_addr(array(
		'REMOTE_ADDR'          => '2001:db8::1',
		'HTTP_X_FORWARDED_FOR' => '2001:db8:ffff::5',
	), array('HTTP_X_FORWARDED_FOR'), array('2001:db8::/64')))->toBe('2001:db8:ffff::5');
});

test('accepts a single trusted proxy given as a string', function () {
	expect(resolve_client_addr(array(
		'REMOTE_ADDR'          => '192.0.2.10',
		'HTTP_X_FORWARDED_FOR' => '198.51.100.66',
	), array('HTTP_X_FORWARDED_FOR'), '10.0.0.1'))->toBe('192.0.2.10');
});

test('maps dash-style header names when trusted proxies are set', function () {
	expect(resolve_client_addr(array(
		'REMOTE_ADDR'          => '10.0.0.1',
		'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
	), array('X-Forwarded-For'), array('10.0.0.1')))->toBe('203.0.113.9');
});

test('falls back to REMOTE_ADDR when a hop is not an address', function () {
	expect(resolve_client_addr(array(
		'REMOTE_ADDR'          => '10.0.0.1',
		'HTTP_X_FORWARDED_FOR' => '203.0.113.9, bogus',
	), array('HTTP_X_FORWARDED_FOR'), array('10.0.0.1')))->toBe('10.0.0.1');
});

test('uses REMOTE_ADDR when trusted proxies are set but headers are off', function () {
	expect(resolve_client_addr(array(
		'REMOTE_ADDR'          => '10.0.0.1',
		'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
	), null, array('10.0.0.1')))->toBe('10.0.0.1');
});

test('ignores a forwarded proto header from a peer outside the trusted list', function () {
	expect(forwarded_https(array(
		'REMOTE_ADDR'            => '192.0.2.10',
		'HTTP_X_FORWARDED_PROTO' => 'https',
	), array('HTTP_X_FORWARDED_FOR'), array('10.0.0.0/24')))->toBeFalse();
});

dataset('forwarded https headers', array(
	'proto' => array(array('HTTP_X_FORWARDED_PROTO' => 'HTTPS')),
	'ssl'   => array(array('HTTP_X_FORWARDED_SSL' => 'on')),
));

test('honours a forwarded proto header from a trusted proxy', function ($headers) {
	expect(forwarded_https(array('REMOTE_ADDR' => '10.0.0.1') + $headers, array('HTTP_X_FORWARDED_FOR'), array('10.0.0.0/24')))->toBeTrue();
})->with('forwarded https headers');

test('remote_agent authorizes on the resolved address, never a raw header', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/remote_agent.php');

	preg_match('/^function remote_client_authorized\(.*?^}\n/ms', $source, $match);

	expect($match[0])->toContain('$client_addr = get_client_addr();')
		->and($match[0])->not->toContain('$_SERVER');
});
