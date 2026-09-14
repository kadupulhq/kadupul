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
 * An ip: token is keyed only on the secret, the client address and the time.
 * Anyone who can load a Cacti page without cookies from the victim's apparent
 * address (a shared NAT, or a proxy without proxy_headers) receives one that a
 * cross-site POST under Basic auth would pass.
 */

namespace CsrfIpTokenTest;

$basePath = dirname(__DIR__, 4);
$GLOBALS['config'] = array(
	'base_path'    => $basePath,
	'include_path' => $basePath . '/include',
	'is_web'       => false,
);
$config = $GLOBALS['config'];

require_once($basePath . '/include/csrf.php');

/**
 * The csrf-magic settings as shipped, without disturbing the loaded ones.
 *
 * @return array<string, mixed>
 */
function shipped_conf() {
	$saved = $GLOBALS['csrf'];

	include(dirname(__DIR__, 4) . '/include/vendor/csrf/csrf-conf.php');

	$conf            = $GLOBALS['csrf'];
	$GLOBALS['csrf'] = $saved;

	return $conf;
}

test('the shipped configuration refuses IP-bound tokens', function () {
	expect(shipped_conf()['allow-ip'])->toBeFalse();
});

test('a cookieless IP token does not pass the shipped configuration', function () {
	$saved_csrf   = $GLOBALS['csrf'];
	$saved_cookie = $_COOKIE;
	$saved_addr   = $_SERVER['REMOTE_ADDR'] ?? null;

	try {
		$GLOBALS['csrf']             = shipped_conf();
		$GLOBALS['csrf']['secret']   = str_repeat('b', 64);
		$GLOBALS['csrf']['hash']     = 'sha256';
		$GLOBALS['csrf']['log_file'] = '';

		$_COOKIE                = array();
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';

		$addr = \csrf_get_client_addr();

		expect($addr)->not->toBeEmpty();

		$token = 'ip:' . \csrf_hash($addr);

		expect(\csrf_check_tokens($token))->toBeFalse();

		/* the same token passes once IP tokens are allowed, so the setting is what refuses it */
		$GLOBALS['csrf']['allow-ip'] = true;

		expect(\csrf_check_tokens($token))->toBeTrue();
	} finally {
		$GLOBALS['csrf'] = $saved_csrf;
		$_COOKIE         = $saved_cookie;

		if ($saved_addr === null) {
			unset($_SERVER['REMOTE_ADDR']);
		} else {
			$_SERVER['REMOTE_ADDR'] = $saved_addr;
		}
	}
});
