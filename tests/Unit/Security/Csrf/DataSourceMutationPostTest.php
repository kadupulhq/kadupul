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
 * the data_sources.php actions, so a same-site link or embed could still run
 * them. data_sources.php now refuses every non-POST request for them itself.
 */

namespace DataSourceMutationPostTest;

/**
 * Runs the data_sources.php dispatch switch in a child process with the real
 * include/csrf.php method helpers and a stub for every handler.
 *
 * @param string                $method  The request method.
 * @param string                $action  The action.
 * @param array<string, string> $request Other request variables.
 * @param array<string, string> $server  Request headers as $_SERVER keys.
 *
 * @return string The handlers reached, or the response code when refused.
 */
function run_data_sources($method, $action, array $request = array(), array $server = array()) {
	$root    = dirname(__DIR__, 4);
	$csrf    = file_get_contents($root . '/include/csrf.php');
	$source  = file_get_contents($root . '/data_sources.php');
	$helpers = '';

	foreach (array('csrf_require_post', 'csrf_request_is_cross_site', 'csrf_request_host_matches', 'csrf_strip_host_port') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $csrf, $matches) === 1) {
			$helpers .= $matches[0];
		}
	}

	expect(preg_match('/^switch \(get_request_var\(\'action\'\)\) \{(.*?)^}$/ms', $source, $switch))->toBe(1);

	$stubs = '';
	foreach (array(
		'ds' => 'list', 'ds_edit' => 'ds_edit', 'data_edit' => 'data_edit', 'form_save' => 'save',
		'form_actions' => 'actions', 'ds_rrd_add' => 'rrd_add', 'ds_rrd_remove' => 'rrd_remove',
		'ds_enable' => 'ds_enable', 'ds_disable' => 'ds_disable', 'ds_remove' => 'ds_remove',
		'get_allowed_ajax_hosts' => 'ajax_hosts',
	) as $function => $name) {
		$stubs .= 'function ' . $function . '($x = null, $y = null, $z = null) { $GLOBALS["reached"][] = "' . $name . '"; }' . "\n";
	}

	$script = '<?php
		function get_request_var($v) { return isset($_REQUEST[$v]) ? $_REQUEST[$v] : "3"; }
		function get_filter_request_var($v) { return get_request_var($v); }
		function get_nfilter_request_var($v) { return get_request_var($v); }
		function isset_request_var($v) { return isset($_REQUEST[$v]); }
		function top_header() {}
		function bottom_footer() {}
		' . $stubs . $helpers . '
		$_SERVER  = ' . var_export($server + array('REQUEST_METHOD' => $method, 'SERVER_NAME' => 'cacti.example'), true) . ';
		$_REQUEST = ' . var_export(array('action' => $action) + $request, true) . ';
		$reached  = array();
		register_shutdown_function(function () {
			print empty($GLOBALS["reached"]) ? (string) http_response_code() : implode(",", $GLOBALS["reached"]);
		});
		switch (get_request_var(\'action\')) {' . $switch[1] . '}';

	$file = tempnam(sys_get_temp_dir(), 'ds');
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
	expect(run_data_sources('GET', $action, $request))->toBe('405', $action)
		->and(run_data_sources('GET', $action, $request, array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('405', $action)
		->and(run_data_sources('GET', $action, $request, array('HTTP_SEC_FETCH_SITE' => 'cross-site')))->toBe('405', $action)
		->and(run_data_sources('HEAD', $action, $request))->toBe('405', $action);
}

test('data source bulk actions refuse any GET that carries selected_items', function () {
	foreach (array('1', '3', '6', '7', '8') as $drp_action) {
		expect_refused('actions', array('selected_items' => 'a:1:{i:0;i:3;}', 'drp_action' => $drp_action, 'delete_type' => '3'));
	}

	expect(run_data_sources('POST', 'actions', array('selected_items' => 'a:1:{i:0;i:3;}', 'drp_action' => '1')))->toBe('actions')
		->and(run_data_sources('GET', 'actions', array('drp_action' => '1'), array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('actions');
});

/**
 * @return array<int, string>
 */
function data_source_mutations() {
	return array('ds_enable', 'ds_disable', 'ds_remove', 'rrd_add', 'rrd_remove');
}

test('per-data-source mutations refuse any GET, including a same-site one', function () {
	foreach (data_source_mutations() as $action) {
		expect_refused($action, array('id' => '3', 'local_data_id' => '3'));
	}
});

test('per-data-source mutations still run on POST', function () {
	foreach (data_source_mutations() as $action) {
		expect(run_data_sources('POST', $action, array('id' => '3', 'local_data_id' => '3')))->toBe($action, $action);
	}
});

test('data source edit page sends its mutations by POST with the csrf token', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/data_sources.php');
	$source = preg_replace('/\s+/', ' ', $source);
	$source = str_replace('htmlspecialchars( ', 'htmlspecialchars(', $source);

	expect($source)->toContain("<a class='hyperLink cactiPostAction' href='#' data-url='<?php print html_escape('data_sources.php?action=ds_' . (\$data['active'] == 'on' ? 'dis' : 'en') . 'able&id=' . (int) get_request_var('id'))")
		->and($source)->toContain("<a class='pic deleteMarker fa fa-times cactiPostAction' href='#' data-url='\" . htmlspecialchars('data_sources.php?action=rrd_remove&id='")
		->and($source)->toContain("<a class='linkOverDark cactiPostAction' href='#' data-url='\" . htmlspecialchars('data_sources.php?action=rrd_add&id='")
		->and($source)->not->toContain("href='<?php print html_escape('data_sources.php?action=ds_'")
		->and($source)->not->toContain("href='\" . html_escape('data_sources.php?action=rrd_")
		->and($source)->not->toContain("href='\" . htmlspecialchars('data_sources.php?action=rrd_");
});
