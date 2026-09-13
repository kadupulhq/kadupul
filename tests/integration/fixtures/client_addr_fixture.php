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
 * Router served by the built-in PHP web server
 * (`php -S 127.0.0.1:<port> -t tests/integration/fixtures client_addr_fixture.php`).
 *
 * It sets $proxy_headers and $trusted_proxies the way include/config.php
 * does, copies proxy_headers into $config as include/global.php does, loads
 * the real lib/functions.php, and answers with get_client_addr() and the
 * session cookie Secure decision taken from include/global.php. The
 * settings arrive as JSON in CLIENT_ADDR_PROXY_HEADERS and
 * CLIENT_ADDR_TRUSTED_PROXIES; an absent variable leaves the setting unset.
 */

$root = dirname(__DIR__, 3);

$value = getenv('CLIENT_ADDR_PROXY_HEADERS');
$proxy_headers = ($value === false ? null : json_decode($value, true));

$value = getenv('CLIENT_ADDR_TRUSTED_PROXIES');
if ($value !== false) {
	$trusted_proxies = json_decode($value, true);
}

$config = array(
	'is_web'        => true,
	'poller_id'     => 1,
	'proxy_headers' => (isset($proxy_headers) ? $proxy_headers : []),
);

require_once $root . '/include/global_constants.php';
require_once $root . '/lib/functions.php';

/* global_arrays.php runs plugin hooks, so take only the header allowlist */
preg_match('/^\$allowed_proxy_headers\s*=\s*array\(.*?\);/ms', file_get_contents($root . '/include/global_arrays.php'), $match);

// test-only eval of an array literal read from this repository, not request input
eval($match[0]);

$client_addr = get_client_addr();

preg_match('/^\t\$https = \(!empty\(\$_SERVER\[\'HTTPS\'\]\).*?(?=^\tif \(\$https\) \{)/ms', file_get_contents($root . '/include/global.php'), $match);

// test-only eval of the global.php block read from this repository, not request input
eval($match[0]);

header('Content-Type: application/json');

print json_encode(array(
	'client_addr'          => $client_addr,
	'cookie_secure'        => $https,
	/* the whole release/1.2.31 condition, include/global.php:453 */
	'cookie_secure_1_2_31' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off'),
));
