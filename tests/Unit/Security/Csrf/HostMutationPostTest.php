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
 * include/global.php lets a GET the browser does not mark as cross-site reach
 * the host.php device actions, so a same-site link or embed could still run
 * them. host.php now refuses every non-POST request for them itself.
 */

namespace HostMutationPostTest;

/**
 * Runs the host.php dispatch switch in a child process with the real
 * include/csrf.php method helpers and a stub for every handler.
 *
 * @param string                $method  The request method.
 * @param string                $action  The action.
 * @param array<string, string> $request Other request variables.
 * @param array<string, string> $server  Request headers as $_SERVER keys.
 *
 * @return string The handlers reached, or the response code when refused.
 */
function run_host($method, $action, array $request = array(), array $server = array()) {
	$root    = dirname(__DIR__, 4);
	$csrf    = file_get_contents($root . '/include/csrf.php');
	$source  = file_get_contents($root . '/host.php');
	$helpers = '';

	foreach (array('csrf_require_post', 'csrf_request_is_cross_site', 'csrf_request_host_matches', 'csrf_strip_host_port') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $csrf, $matches) === 1) {
			$helpers .= $matches[0];
		}
	}

	expect(preg_match('/^switch \(get_request_var\(\'action\'\)\) \{(.*?)^}$/ms', $source, $switch))->toBe(1);

	$stubs = '';
	foreach (array(
		'host' => 'list', 'host_edit' => 'edit', 'host_export' => 'export', 'form_save' => 'save',
		'form_actions' => 'actions', 'host_reindex' => 'reindex', 'host_add_gt' => 'gt_add',
		'host_remove_gt' => 'gt_remove', 'host_add_query' => 'query_add', 'host_remove_query' => 'query_remove',
		'host_change_query' => 'query_change', 'host_reload_query' => 'query_reload',
		'api_device_ping_device' => 'ping_host', 'enable_device_debug' => 'enable_debug',
		'disable_device_debug' => 'disable_debug', 'push_out_host' => 'repopulate',
		'get_site_locations' => 'ajax_locations',
	) as $function => $name) {
		$stubs .= 'function ' . $function . '($x = null) { $GLOBALS["reached"][] = "' . $name . '"; }' . "\n";
	}

	$script = '<?php
		define("MESSAGE_LEVEL_INFO", 1);
		define("MESSAGE_LEVEL_ERROR", 3);
		function get_request_var($v) { return isset($_REQUEST[$v]) ? $_REQUEST[$v] : "3"; }
		function get_filter_request_var($v) { return get_request_var($v); }
		function get_nfilter_request_var($v) { return get_request_var($v); }
		function isset_request_var($v) { return isset($_REQUEST[$v]); }
		function raise_message($id, $text = "", $level = 0) { return true; }
		function top_header() {}
		function bottom_footer() {}
		function __($text) { return $text; }
		' . $stubs . $helpers . '
		$_SERVER  = ' . var_export($server + array('REQUEST_METHOD' => $method, 'SERVER_NAME' => 'cacti.example'), true) . ';
		$_REQUEST = ' . var_export(array('action' => $action) + $request, true) . ';
		$reached  = array();
		register_shutdown_function(function () {
			print empty($GLOBALS["reached"]) ? (string) http_response_code() : implode(",", $GLOBALS["reached"]);
		});
		switch (get_request_var(\'action\')) {' . $switch[1] . '}';

	$file = tempnam(sys_get_temp_dir(), 'host');
	file_put_contents($file, $script);

	try {
		return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
	} finally {
		unlink($file);
	}
}

/**
 * Asserts that no request other than a POST reaches the action, including a
 * GET the browser marks as same-origin.
 *
 * @param string                $action  The action.
 * @param array<string, string> $request Other request variables.
 *
 * @return void
 */
function expect_refused($action, array $request) {
	expect(run_host('GET', $action, $request))->toBe('405', $action)
		->and(run_host('GET', $action, $request, array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('405', $action)
		->and(run_host('GET', $action, $request, array('HTTP_SEC_FETCH_SITE' => 'cross-site')))->toBe('405', $action)
		->and(run_host('HEAD', $action, $request))->toBe('405', $action);
}

test('device bulk actions refuse any GET that carries selected_items', function () {
	$selected = array('selected_items' => 'a:1:{i:0;i:3;}', 'drp_action' => '1');

	expect_refused('actions', $selected);

	expect(run_host('POST', 'actions', $selected))->toBe('actions')
		->and(run_host('GET', 'actions', array('drp_action' => '1'), array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('actions');
});

/**
 * @return array<int, string>
 */
function device_mutations() {
	return array(
		'gt_add', 'gt_remove', 'query_add', 'query_remove', 'query_change',
		'query_reload', 'query_verbose', 'enable_debug', 'disable_debug', 'repopulate',
	);
}

test('per-device mutations refuse any GET, including a same-site one', function () {
	foreach (device_mutations() as $action) {
		expect_refused($action, array('host_id' => '3'));
	}
});

test('per-device mutations still run on POST', function () {
	foreach (device_mutations() as $action) {
		expect(run_host('POST', $action, array('host_id' => '3')))->toBe($action === 'query_verbose' ? 'query_reload' : $action, $action);
	}
});

test('device edit page sends per-device mutations by POST with the csrf token', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/host.php');

	expect($source)->not->toContain('function hostPageLoad(')
		->and($source)->not->toContain("strURL = 'host.php?action=query_reload")
		->and($source)->not->toContain("'host.php?action=query_verbose&id='")
		->and($source)->not->toContain("urlPath+'host.php?action=query_change")
		->and($source)->toContain('postData.__csrf_magic = csrfMagicToken;')
		->and($source)->toContain("hostPagePost('host.php?action=query_reload', {")
		->and($source)->toContain("hostPagePost('host.php?action=query_verbose', {")
		->and($source)->toContain("hostPagePost('host.php?action=query_change', {");

	foreach (array('enable_debug', 'disable_debug', 'repopulate') as $action) {
		expect($source)->toContain("<a class='hyperLink cactiPostAction' href='#' data-url='\" . html_escape('host.php?action=" . $action . '&host_id=');
	}
});
