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
 * The session cookie was Secure only when PHP saw HTTPS or a trusted proxy
 * said so, and the remember-me cookie only when PHP saw HTTPS. With
 * force_https on, every page is served over HTTPS, so the session cookie is
 * now Secure then as well, and the remember-me cookie follows the session
 * cookie, which also covers the trusted-proxy case.
 *
 * The shipped code runs in a child process inside a namespace, so its
 * unqualified ini_set() and setcookie() calls reach recording stubs.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function secure_cookie_global(array $server, array $config, bool $proxy_headers = false) : bool {
	$global = file_get_contents(dirname(__DIR__, 4) . '/include/global.php');
	$start  = strpos($global, "\t\$https = (!empty(\$_SERVER['HTTPS'])");
	$end    = strpos($global, "\t\$config['cookie_options']", (int) $start);

	expect($start)->not->toBeFalse()
		->and($end)->not->toBeFalse();

	$block  = substr($global, $start, $end - $start);
	$source = '<?php
namespace SecureCookieFlagProbe;

$scenario = json_decode(stream_get_contents(STDIN), true);
$GLOBALS["ini"] = array();

function ini_set($name, $value) { $GLOBALS["ini"][$name] = $value; }
function read_config_option($name) { return $GLOBALS["scenario"]["config"][$name] ?? ""; }
function is_trusted_proxy_addr($addr) { return true; }

$_SERVER = $scenario["server"];
$config  = array("proxy_headers" => $scenario["proxy_headers"]);
$options = array();
' . $block . '
print json_encode(array("secure" => !empty($GLOBALS["ini"]["session.cookie_secure"]) && !empty($options["cookie_secure"])));
';

	return cacti_test_run_php_source($source, array('server' => $server, 'config' => $config, 'proxy_headers' => $proxy_headers))['secure'];
}

function secure_cookie_remember(array $server, array $cookie_options) : bool {
	$source = '<?php
namespace SecureCookieFlagProbe;

$scenario = json_decode(stream_get_contents(STDIN), true);
$GLOBALS["cookies"] = array();

function setcookie($name, $value, $options = array()) { $GLOBALS["cookies"][$name] = $options; return true; }

$_SERVER = $scenario["server"];
$config  = array("url_path" => "/cacti/", "cookie_options" => $scenario["cookie_options"]);
' . cacti_test_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php'), 'cacti_cookie_session_set') . '

cacti_cookie_session_set(42, 0, "secret");
print json_encode(array("secure" => $GLOBALS["cookies"]["cacti_remembers"]["secure"]));
';

	return cacti_test_run_php_source($source, array('server' => $server, 'cookie_options' => $cookie_options))['secure'];
}

test('the session cookie is Secure when force_https is on', function () {
	expect(secure_cookie_global(array(), array('force_https' => 'on')))->toBeTrue();
});

test('the session cookie keeps its 1.2.31 flag over plain HTTP without force_https', function () {
	expect(secure_cookie_global(array(), array()))->toBeFalse()
		->and(secure_cookie_global(array('HTTPS' => 'on'), array()))->toBeTrue()
		->and(secure_cookie_global(array('REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_PROTO' => 'https'), array(), true))->toBeTrue();
});

test('the remember-me cookie is Secure whenever the session cookie is', function () {
	expect(secure_cookie_remember(array(), array('cookie_secure' => true)))->toBeTrue()
		->and(secure_cookie_remember(array('HTTPS' => 'on'), array()))->toBeTrue()
		->and(secure_cookie_remember(array(), array()))->toBeFalse();
});
