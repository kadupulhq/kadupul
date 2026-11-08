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
 * Runs the shipped auth_login_redirect() for the "return to referer" login
 * option with only REQUEST_URI set, and prints the Location header it sends.
 *
 * The function is evaluated in a namespace that defines header(), so its
 * header() calls are captured while every other call reaches the shipped
 * global function or the stubs below.
 */

namespace AuthLoginRedirectProbe {
	function header($value) {
		$GLOBALS['probe_headers'][] = $value;
	}
}

namespace {
	$root = $argv[1];
	require $root . '/tests/Helpers/PhpSource.php';

	$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);

	$config          = array('url_path' => '/cacti/', 'cacti_server_os' => 'unix');
	$_SERVER         = array('REQUEST_URI' => $input['request_uri']);
	$_SESSION        = array('sess_user_id' => 1);
	$probe_headers   = array();
	$probe_realm_ask = array();

	define('POLLER_VERBOSITY_DEBUG', 5);

	function get_nfilter_request_var($name, $default = '') {
		/* the login form posts action=login */
		return $name === 'action' ? 'login' : $default;
	}

	function user_setting_exists($name, $user) { return false; }
	function read_config_option($name, $force = false) { return ''; }
	function read_user_setting($name, $default = false, $force = false, $user = 0) { return $default; }
	function is_realm_allowed($realm, $check_user = false) { return true; }
	function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {}
	function api_plugin_hook_function($name, $args = '') { return $args; }

	function api_user_realm_auth($filename = '') {
		$GLOBALS['probe_realm_ask'][] = $filename;

		return true;
	}

	foreach (array(
		'lib/functions.php'    => array('sanitize_uri', 'is_urlencoded'),
		'lib/html_utility.php' => array('validate_local_redirect_path'),
		'lib/auth.php'         => array('auth_basename', 'auth_login_redirect'),
	) as $file => $functions) {
		$source = file_get_contents($root . '/' . $file);

		if ($source === false) {
			throw new RuntimeException('Cannot read ' . $file);
		}

		foreach ($functions as $function) {
			$body = test_php_function_source($source, $function);

			if ($function === 'auth_login_redirect') {
				eval('namespace AuthLoginRedirectProbe; ' . $body);
			} else {
				eval($body);
			}
		}
	}

	register_shutdown_function(function () {
		print json_encode(array(
			'headers' => $GLOBALS['probe_headers'],
			'realm'   => $GLOBALS['probe_realm_ask'],
		), JSON_THROW_ON_ERROR);
	});

	\AuthLoginRedirectProbe\auth_login_redirect('1');
}
