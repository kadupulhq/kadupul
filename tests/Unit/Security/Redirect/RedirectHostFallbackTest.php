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
 * validate_redirect_url() compares an absolute URL with the server's own
 * name. When SERVER_NAME is empty, 1.2.31 used any Host header instead; the
 * Host header now counts only when its host name is listed in $trusted_hosts.
 */

require_once dirname(__DIR__, 4) . '/lib/functions.php';
require_once dirname(__DIR__, 4) . '/lib/html_utility.php';

if (!function_exists('redirect_host_fallback_check')) {
	/**
	 * @param array<string, string|null> $server  Values to set; null unsets.
	 * @param array<string>|null         $trusted $trusted_hosts, or null for unset.
	 */
	function redirect_host_fallback_check(array $server, string $url, ?array $trusted = null) : string {
		$saved_server = $_SERVER;
		$had_config   = array_key_exists('config', $GLOBALS);
		$saved_config = $GLOBALS['config'] ?? null;

		try {
			unset($_SERVER['SERVER_NAME'], $_SERVER['HTTP_HOST'], $_SERVER['SERVER_PORT']);

			foreach ($server as $key => $value) {
				if ($value !== null) {
					$_SERVER[$key] = $value;
				}
			}

			$GLOBALS['config'] = is_array($saved_config) ? $saved_config : array();
			unset($GLOBALS['config']['trusted_hosts']);

			if ($trusted !== null) {
				$GLOBALS['config']['trusted_hosts'] = $trusted;
			}

			return validate_redirect_url($url);
		} finally {
			$_SERVER = $saved_server;

			if ($had_config) {
				$GLOBALS['config'] = $saved_config;
			} else {
				unset($GLOBALS['config']);
			}
		}
	}
}

test('an empty SERVER_NAME accepts a same-host URL through a Host header listed in trusted_hosts', function () {
	expect(redirect_host_fallback_check(
		array('SERVER_NAME' => '', 'HTTP_HOST' => 'cacti.example.com'),
		'https://cacti.example.com/cacti/host.php?id=3',
		array('cacti.example.com')
	))->toBe('/cacti/host.php?id=3');

	expect(redirect_host_fallback_check(
		array('SERVER_NAME' => '', 'HTTP_HOST' => 'CACTI.example.com:8080', 'SERVER_PORT' => '8080'),
		'https://cacti.example.com:8080/cacti/graphs.php',
		array('other.example', 'cacti.EXAMPLE.com')
	))->toBe('/cacti/graphs.php');

	expect(redirect_host_fallback_check(
		array('HTTP_HOST' => 'cacti_host'),
		'http://cacti_host/cacti/index.php',
		array('cacti_host')
	))->toBe('/cacti/index.php');
});

test('an empty SERVER_NAME refuses a Host header that is not in trusted_hosts', function () {
	foreach (array(null, array(), array('other.example')) as $trusted) {
		expect(redirect_host_fallback_check(
			array('SERVER_NAME' => '', 'HTTP_HOST' => 'cacti.example.com'),
			'https://cacti.example.com/cacti/host.php',
			$trusted
		))->toBe('index.php');
	}

	/* The attacker-controlled Host header and target name the same host. */
	expect(redirect_host_fallback_check(
		array('SERVER_NAME' => '', 'HTTP_HOST' => 'evil.example'),
		'https://evil.example/phish',
		array('cacti.example.com')
	))->toBe('index.php');
});

test('an empty SERVER_NAME still refuses a mismatched target, a malformed Host header or no Host header', function () {
	expect(redirect_host_fallback_check(
		array('SERVER_NAME' => '', 'HTTP_HOST' => 'cacti.example.com'),
		'https://evil.example/cacti/host.php',
		array('cacti.example.com')
	))->toBe('index.php');

	$malformed = array(
		"cacti.example.com\r\nSet-Cookie: x=1",
		'cacti.example.com/evil',
		'user@cacti.example.com',
		'cacti example.com',
		'cacti.example.com:99999',
	);

	foreach ($malformed as $host) {
		expect(redirect_host_fallback_check(
			array('SERVER_NAME' => '', 'HTTP_HOST' => $host),
			'https://cacti.example.com/cacti/host.php',
			array('cacti.example.com')
		))->toBe('index.php');
	}

	expect(redirect_host_fallback_check(
		array('SERVER_NAME' => '', 'HTTP_HOST' => null),
		'https://cacti.example.com/cacti/host.php',
		array('cacti.example.com')
	))->toBe('index.php');
});

test('an empty SERVER_NAME matches a trusted IPv6 or IPv4 Host header without its brackets or port', function () {
	expect(redirect_host_fallback_check(
		array('SERVER_NAME' => '', 'HTTP_HOST' => '[2001:db8::1]:8443', 'SERVER_PORT' => '8443'),
		'https://[2001:db8::1]:8443/cacti/host.php',
		array('2001:db8::1')
	))->toBe('/cacti/host.php');

	expect(redirect_host_fallback_check(
		array('SERVER_NAME' => '', 'HTTP_HOST' => '[2001:DB8::1]'),
		'https://[2001:db8::1]/cacti/host.php',
		array('[2001:db8::1]')
	))->toBe('/cacti/host.php');

	expect(redirect_host_fallback_check(
		array('SERVER_NAME' => '', 'HTTP_HOST' => '192.0.2.10:8080', 'SERVER_PORT' => '8080'),
		'https://192.0.2.10:8080/cacti/host.php',
		array('192.0.2.10')
	))->toBe('/cacti/host.php');

	expect(redirect_host_fallback_check(
		array('SERVER_NAME' => '', 'HTTP_HOST' => '[2001:db8::1]:8443', 'SERVER_PORT' => '8443'),
		'https://[2001:db8::1]:8443/cacti/host.php',
		array('2001:db8::2')
	))->toBe('index.php');

	expect(redirect_host_fallback_check(
		array('SERVER_NAME' => '', 'HTTP_HOST' => '[2001:db8::1]'),
		'https://[2001:db8::2]/cacti/host.php',
		array('2001:db8::1')
	))->toBe('index.php');
});

test('a configured SERVER_NAME still ignores the Host header', function () {
	expect(redirect_host_fallback_check(
		array('SERVER_NAME' => 'monitor.example', 'HTTP_HOST' => 'cacti.example.com'),
		'https://cacti.example.com/cacti/host.php',
		array('cacti.example.com')
	))->toBe('index.php');

	expect(redirect_host_fallback_check(
		array('SERVER_NAME' => 'monitor.example', 'HTTP_HOST' => 'cacti.example.com'),
		'https://monitor.example/cacti/host.php'
	))->toBe('/cacti/host.php');
});

test('relative targets that a browser reads as another host are refused', function () {
	foreach (array('/\\evil.example', '/\\/evil.example', '//evil.example', '\\\\evil.example') as $url) {
		expect(redirect_host_fallback_check(array('SERVER_NAME' => '', 'HTTP_HOST' => 'cacti.example.com'), $url, array('cacti.example.com')))
			->toBe('index.php');
	}

	expect(redirect_host_fallback_check(array('SERVER_NAME' => '', 'HTTP_HOST' => 'cacti.example.com'), '/cacti/host.php?id=3'))
		->toBe('/cacti/host.php?id=3');
});
