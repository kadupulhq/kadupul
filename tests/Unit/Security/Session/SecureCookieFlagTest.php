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
 * The remember-me cookie was Secure only when PHP saw HTTPS, while the session
 * cookie is also Secure when a trusted TLS-terminating proxy says the request
 * came over HTTPS. The remember-me cookie now follows the session cookie.
 *
 * The shipped code runs in a child process inside a namespace, so its
 * unqualified setcookie() calls reach a recording stub.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

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

test('the remember-me cookie is Secure whenever the session cookie is', function () {
	expect(secure_cookie_remember(array(), array('cookie_secure' => true)))->toBeTrue()
		->and(secure_cookie_remember(array('HTTPS' => 'on'), array()))->toBeTrue()
		->and(secure_cookie_remember(array(), array()))->toBeFalse();
});
