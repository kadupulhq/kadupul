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
 * The profile page cleared user settings, reset one setting and ended every
 * remembered login through $.get, and csrf-magic checks the token only on
 * POST, so any same-site link or embed could run them. include/global.php let
 * such a GET through. auth_profile.php now refuses every state change that is
 * not a POST, and its page sends them by POST with the token.
 */

namespace AuthProfilePostActionsTest;

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

/**
 * Runs csrf-magic's POST check and then the auth_profile.php dispatch switch
 * in a child process, with the real include/csrf.php method helpers and a
 * stub for every handler.
 *
 * @param string                $method The request method.
 * @param string                $action The action.
 * @param string                $token  'valid', 'forged' or 'none'.
 * @param array<string, string> $server Request headers as $_SERVER keys.
 *
 * @return string The handler reached, 'csrf' when csrf-magic refused the
 *                request, or the response code when the page refused it.
 */
function run_profile($method, $action, $token = 'valid', array $server = array()) {
	$root    = dirname(__DIR__, 4);
	$csrf    = file_get_contents($root . '/include/csrf.php');
	$profile = file_get_contents($root . '/auth_profile.php');
	$helpers = '';

	foreach (array('csrf_require_post', 'csrf_request_is_cross_site', 'csrf_request_host_matches', 'csrf_strip_host_port') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $csrf, $matches) === 1) {
			$helpers .= $matches[0];
		}
	}

	expect(preg_match('/^switch \(get_request_var\(\'action\'\)\) \{(.*?)^}$/ms', $profile, $switch))->toBe(1);

	$stubs = '';
	foreach (array('form_save', 'api_auth_logout_everywhere', 'api_auth_clear_user_settings', 'api_auth_clear_user_setting', 'api_auth_update_user_setting') as $function) {
		$stubs .= 'function ' . $function . '($x = null, $y = null) { $GLOBALS["reached"] = "' . $function . '"; }' . "\n";
	}

	$source = '<?php
$scenario = json_decode(stream_get_contents(STDIN), true);
$reached  = "";

$_SERVER  = $scenario["server"] + array("REQUEST_METHOD" => $scenario["method"], "SERVER_NAME" => "cacti.example", "REMOTE_ADDR" => "192.0.2.10");
$_COOKIE  = array("Cacti" => "session");
$request  = array("action" => $scenario["action"], "tab" => "general", "name" => "show_graph_title", "value" => "on");
$_POST    = $scenario["method"] === "POST" ? $request : array();
$_GET     = $scenario["method"] === "POST" ? array() : $request;
$_REQUEST = $request;

register_shutdown_function(function () {
	print json_encode(array("result" => $GLOBALS["reached"] !== "" ? $GLOBALS["reached"] : (string) http_response_code()));
});

function csrf_startup() {
	csrf_conf("secret", str_repeat("a", 64));
	csrf_conf("hash", "sha256");
	csrf_conf("key", "kadupul-profile");
	csrf_conf("cookie", false);
	csrf_conf("session", false);
	csrf_conf("auto-session", false);
	csrf_conf("rewrite", false);
	csrf_conf("defer", true);
	csrf_conf("callback", "csrf_refused");
}

function csrf_refused($tokens) {
	$GLOBALS["reached"] = "csrf";
}

require ' . var_export($root . '/include/vendor/csrf/csrf-magic.php', true) . ';

if ($scenario["method"] === "POST" && $scenario["token"] !== "none") {
	$_POST["__csrf_magic"] = $scenario["token"] === "valid" ? csrf_get_tokens() : "key:" . str_repeat("0", 64) . "," . time();
}

/* include/auth.php runs this before the page dispatches */
csrf_check();

function get_request_var($name) { return $_REQUEST[$name] ?? ""; }
function get_nfilter_request_var($name) { return get_request_var($name); }
function api_plugin_hook_function($name, $args = null) { $GLOBALS["reached"] = $name; }
' . $stubs . $helpers . '
switch (get_request_var(\'action\')) {' . $switch[1] . '}';

	return \cacti_test_run_php_source($source, array('method' => $method, 'action' => $action, 'token' => $token, 'server' => $server))['result'];
}

$profileActions = array(
	'save'                => 'form_save',
	'update_data'         => 'api_auth_update_user_setting',
	'clear_user_settings' => 'api_auth_clear_user_settings',
	'reset_default'       => 'api_auth_clear_user_setting',
	'logout_everywhere'   => 'api_auth_logout_everywhere',
);

test('a POST with the token reaches every profile action as before', function () use ($profileActions) {
	foreach ($profileActions as $action => $handler) {
		expect(run_profile('POST', $action))->toBe($handler, $action);
	}
});

test('csrf-magic refuses a profile POST without a valid token', function () use ($profileActions) {
	foreach (array_keys($profileActions) as $action) {
		expect(run_profile('POST', $action, 'none'))->toBe('csrf', $action)
			->and(run_profile('POST', $action, 'forged'))->toBe('csrf', $action);
	}
});

test('no profile action runs from a GET, even one the browser marks as same-origin', function () use ($profileActions) {
	foreach (array_keys($profileActions) as $action) {
		expect(run_profile('GET', $action, 'none'))->toBe('405', $action)
			->and(run_profile('GET', $action, 'none', array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('405', $action)
			->and(run_profile('GET', $action, 'none', array('HTTP_SEC_FETCH_SITE' => 'cross-site')))->toBe('405', $action)
			->and(run_profile('HEAD', $action, 'none'))->toBe('405', $action);
	}
});

test('the profile page sends its state changes by POST with the token', function () {
	$profile = file_get_contents(dirname(__DIR__, 4) . '/auth_profile.php');

	expect($profile)->not->toContain("\$.get('auth_profile.php")
		->and($profile)->toContain("\$.post('auth_profile.php', {action: 'clear_user_settings', tab: currentTab, __csrf_magic: csrfMagicToken}")
		->and($profile)->toContain("\$.post('auth_profile.php', {action: 'logout_everywhere', __csrf_magic: csrfMagicToken}")
		->and($profile)->toContain("\$.post('auth_profile.php', {action: 'reset_default', tab: currentTab, name: id, __csrf_magic: csrfMagicToken}");
});
