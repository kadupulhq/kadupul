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
 * the graphs.php and graphs_items.php actions, so a same-site link or embed
 * could still run them. Both pages now refuse every non-POST request for them.
 */

namespace GraphMutationPostTest;

/**
 * Runs the dispatch switch of graphs.php or graphs_items.php in a child
 * process with the real include/csrf.php method helpers and a stub for every
 * handler.
 *
 * @param string                $file    graphs.php or graphs_items.php.
 * @param string                $method  The request method.
 * @param string                $action  The action.
 * @param array<string, string> $request Other request variables.
 * @param array<string, string> $server  Request headers as $_SERVER keys.
 * @param int|null              $owner   The local_graph_id the stub database gives the item.
 *
 * @return string The handlers reached, or the response code when refused.
 */
function run_graphs($file, $method, $action, array $request = array(), array $server = array(), $owner = null) {
	$root    = dirname(__DIR__, 4);
	$csrf    = file_get_contents($root . '/include/csrf.php');
	$source  = file_get_contents($root . '/' . $file);
	$helpers = '';

	foreach (array('csrf_require_post', 'csrf_request_is_cross_site', 'csrf_request_host_matches', 'csrf_strip_host_port') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $csrf, $matches) === 1) {
			$helpers .= $matches[0];
		}
	}

	if (preg_match_all('/^function graphs_items_require_\w+\(.*?^}\R/ms', $source, $matches)) {
		$helpers .= implode('', $matches[0]);
	}

	expect(preg_match('/^switch \(get_request_var\(\'action\'\)\) \{(.*?)^}$/ms', $source, $switch))->toBe(1);

	$stubs = '';
	foreach (array(
		'graph_management' => 'list', 'graph_edit' => 'graph_edit', 'item' => 'item', 'item_edit' => 'item_edit',
		'item_remove' => 'item_remove', 'item_moveup' => 'item_moveup', 'item_movedown' => 'item_movedown',
		'form_save' => 'save', 'form_actions' => 'actions', 'get_ajax_graph_items' => 'ajax_graph_items',
		'get_allowed_ajax_hosts' => 'ajax_hosts', 'get_allowed_ajax_graph_items' => 'ajax_graph_items',
	) as $function => $name) {
		$stubs .= 'function ' . $function . '($x = null, $y = null, $z = null) { $GLOBALS["reached"][] = "' . $name . '"; }' . "\n";
	}

	$script = '<?php
		function get_request_var($v) { return isset($_REQUEST[$v]) ? $_REQUEST[$v] : ""; }
		function get_filter_request_var($v) { return get_request_var($v); }
		function get_nfilter_request_var($v) { return get_request_var($v); }
		function isset_request_var($v) { return isset($_REQUEST[$v]); }
		function isempty_request_var($v) { return get_request_var($v) == ""; }
		function cacti_log($m, $o = false, $f = "") { $GLOBALS["reached"][] = "log:" . $f . ":" . $m; }
		function db_fetch_cell_prepared($sql, $params = array()) {
			$owner = ' . var_export($owner, true) . ';

			/* Mirrors the join: a row exists only when the item belongs to the graph asked for. */
			return ($owner !== null && $owner > 0 && (string) $owner === (string) $params[1]) ? 1 : 0;
		}
		function top_header() {}
		function bottom_footer() {}
		function validate_graph_request_vars() {}
		' . $stubs . $helpers . '
		$_SERVER  = ' . var_export($server + array('REQUEST_METHOD' => $method, 'SERVER_NAME' => 'cacti.example'), true) . ';
		$_REQUEST = ' . var_export(array('action' => $action) + $request, true) . ';
		$reached  = array();
		register_shutdown_function(function () {
			print empty($GLOBALS["reached"]) ? (string) http_response_code() : implode(",", $GLOBALS["reached"]);
		});
		switch (get_request_var(\'action\')) {' . $switch[1] . '}';

	$tmp = tempnam(sys_get_temp_dir(), 'gr');
	file_put_contents($tmp, $script);

	try {
		return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1');
	} finally {
		unlink($tmp);
	}
}

/**
 * Asserts that no request other than a POST reaches the action, including a
 * GET the browser marks as same-origin.
 *
 * @param string                $file    graphs.php or graphs_items.php.
 * @param string                $action  The action.
 * @param array<string, string> $request Other request variables.
 * @param int|null              $owner   The local_graph_id the stub database gives the item.
 *
 * @return void
 */
function expect_refused($file, $action, array $request, $owner = null) {
	expect(run_graphs($file, 'GET', $action, $request, array(), $owner))->toBe('405', $action)
		->and(run_graphs($file, 'GET', $action, $request, array('HTTP_SEC_FETCH_SITE' => 'same-origin'), $owner))->toBe('405', $action)
		->and(run_graphs($file, 'GET', $action, $request, array('HTTP_SEC_FETCH_SITE' => 'cross-site'), $owner))->toBe('405', $action)
		->and(run_graphs($file, 'HEAD', $action, $request, array(), $owner))->toBe('405', $action);
}

test('graph bulk actions refuse any GET that carries selected_items', function () {
	foreach (array('1', '2', '3', '4', '5', '6', '8', '9', '10', '11', 'tr_1') as $drp_action) {
		expect_refused('graphs.php', 'actions', array('selected_items' => 'a:1:{i:0;i:3;}', 'drp_action' => $drp_action, 'delete_type' => '2'));
	}

	expect(run_graphs('graphs.php', 'POST', 'actions', array('selected_items' => 'a:1:{i:0;i:3;}', 'drp_action' => '1', 'delete_type' => '2')))->toBe('actions')
		->and(run_graphs('graphs.php', 'GET', 'actions', array('drp_action' => '1'), array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('actions');
});

/**
 * @return array<int, string>
 */
function graph_item_mutations() {
	return array('item_remove', 'item_moveup', 'item_movedown');
}

test('graph item mutations refuse any GET, including a same-site one', function () {
	foreach (graph_item_mutations() as $action) {
		expect_refused('graphs_items.php', $action, array('id' => '7', 'local_graph_id' => '3'), 3);
	}
});

test('graph item mutations refuse an item that belongs to another graph or to a template', function () {
	foreach (graph_item_mutations() as $action) {
		foreach (array(array('3', 9), array('3', 0), array('0', 0), array('3', null)) as $case) {
			list($local_graph_id, $owner) = $case;

			expect(run_graphs('graphs_items.php', 'POST', $action, array('id' => '7', 'local_graph_id' => $local_graph_id), array(), $owner))
				->toBe('log:AUTH:WARNING: Rejected graphs_items.php?action=' . $action . ' for item 7 outside graph ' . (int) $local_graph_id, $action);
		}
	}
});

test('graph item mutations still run on POST for an item of the graph named', function () {
	foreach (graph_item_mutations() as $action) {
		expect(run_graphs('graphs_items.php', 'POST', $action, array('id' => '7', 'local_graph_id' => '3'), array(), 3))->toBe($action, $action);
	}
});
