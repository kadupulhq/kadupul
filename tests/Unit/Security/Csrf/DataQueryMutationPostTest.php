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
 * include/global.php and the data_queries.php page guards let a GET the
 * browser does not mark as cross-site reach the data query changes, so a
 * same-site link or embed could still run them. data_queries.php now refuses
 * every non-POST request for them itself.
 */

namespace DataQueryMutationPostTest;

/**
 * Runs the data_queries.php dispatch switch in a child process with the real
 * include/csrf.php method helpers and a stub for every handler.
 *
 * @param string                $method  The request method.
 * @param string                $action  The action.
 * @param array<string, string> $request Other request variables.
 * @param array<string, string> $server  Request headers as $_SERVER keys.
 *
 * @return string The handlers reached, or the response code when refused.
 */
function run_data_queries($method, $action, array $request = array(), array $server = array()) {
	$root    = dirname(__DIR__, 4);
	$csrf    = file_get_contents($root . '/include/csrf.php');
	$source  = file_get_contents($root . '/data_queries.php');
	$helpers = '';

	foreach (array('csrf_require_post', 'csrf_request_is_cross_site', 'csrf_request_host_matches', 'csrf_strip_host_port') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $csrf, $matches) === 1) {
			$helpers .= $matches[0];
		}
	}

	expect(preg_match('/^switch \(get_request_var\(\'action\'\)\) \{(.*?)^}$/ms', $source, $switch))->toBe(1);

	$stubs = '';
	foreach (array(
		'data_query' => 'list', 'data_query_edit' => 'edit', 'data_query_item_edit' => 'item_edit',
		'form_save' => 'save', 'form_actions' => 'actions',
		'data_query_item_moveup_dssv' => 'item_moveup_dssv', 'data_query_item_movedown_dssv' => 'item_movedown_dssv',
		'data_query_item_remove_dssv' => 'item_remove_dssv', 'data_query_item_moveup_gsv' => 'item_moveup_gsv',
		'data_query_item_movedown_gsv' => 'item_movedown_gsv', 'data_query_item_remove_gsv' => 'item_remove_gsv',
		'data_query_item_remove_confirm' => 'item_remove_confirm', 'data_query_item_remove' => 'item_remove',
		'data_query_remove' => 'remove',
	) as $function => $name) {
		$stubs .= 'function ' . $function . '($x = null) { $GLOBALS["reached"][] = "' . $name . '"; }' . "\n";
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

	$file = tempnam(sys_get_temp_dir(), 'dq');
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
	expect(run_data_queries('GET', $action, $request))->toBe('405', $action)
		->and(run_data_queries('GET', $action, $request, array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('405', $action)
		->and(run_data_queries('GET', $action, $request, array('HTTP_SEC_FETCH_SITE' => 'cross-site')))->toBe('405', $action)
		->and(run_data_queries('HEAD', $action, $request))->toBe('405', $action);
}

test('data query bulk actions refuse any GET that carries selected_items', function () {
	foreach (array('1', '2') as $drp_action) {
		expect_refused('actions', array('selected_items' => 'a:1:{i:0;i:3;}', 'drp_action' => $drp_action, 'name_format' => 'copy'));
	}

	expect(run_data_queries('POST', 'actions', array('selected_items' => 'a:1:{i:0;i:3;}', 'drp_action' => '1')))->toBe('actions')
		->and(run_data_queries('GET', 'actions', array('drp_action' => '1'), array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('actions');
});
