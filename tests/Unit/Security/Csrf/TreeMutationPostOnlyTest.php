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
 * The global guard lets a same-site GET through for the tree actions, so a
 * link on the same site could still lock a tree, edit its nodes or reorder
 * the tree list. The editor and the list now post every one of them.
 */

namespace TreeMutationPostOnlyTest;

/**
 * Runs tree.php's action switch with the include/csrf.php method helpers in a
 * child process, because a refusal exits.
 *
 * @param string                $method The request method.
 * @param string                $action The action.
 * @param array<string, string> $server Request headers as $_SERVER keys.
 *
 * @return string The response code, or the handler that ran.
 */
function run_tree($method, $action, array $server = array()) {
	$root   = dirname(__DIR__, 4);
	$tree   = file_get_contents($root . '/tree.php');
	$csrf   = file_get_contents($root . '/include/csrf.php');
	$source = '';

	if (preg_match('/^switch \(get_request_var\(\'action\'\)\) \{.*?^}\R/ms', $tree, $matches) !== 1) {
		return 'no switch';
	}

	$switch = $matches[0];

	foreach (array('csrf_require_post', 'csrf_request_is_cross_site', 'csrf_request_host_matches', 'csrf_strip_host_port') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $csrf, $matches) === 1) {
			$source .= $matches[0];
		}
	}

	$handlers = array(
		'form_save', 'form_actions', 'tree_sort_name_asc', 'tree_sort_name_desc',
		'display_sites', 'display_hosts', 'display_graphs', 'tree_up', 'tree_down', 'tree_dnd',
		'api_tree_lock', 'api_tree_unlock', 'api_tree_copy_node', 'api_tree_create_node',
		'api_tree_delete_node', 'api_tree_move_node', 'api_tree_rename_node', 'api_tree_get_node',
		'get_host_sort_type', 'set_host_sort_type', 'get_branch_sort_type', 'set_branch_sort_type',
	);

	foreach ($handlers as $handler) {
		$source .= 'function ' . $handler . '() { $GLOBALS["ran"] = "' . $handler . '"; }' . "\n";
	}

	$script = '<?php
		function get_request_var($v) { return $v === "action" ? $_REQUEST["action"] : "3"; }
		function get_nfilter_request_var($v) { return get_request_var($v); }
		function get_filter_request_var($v) { return get_request_var($v); }
		function isset_request_var($v) { return true; }
		function top_header() {}
		function bottom_footer() {}
		function tree() {}
		function tree_edit($partial = false) {}
		function tree_require_access($tree_ids, $action) {}
		function tree_branch_tree_id($nodeid) { return 3; }
		function db_fetch_assoc($sql) { return array(); }
		' . $source . '
		$_SERVER  = ' . var_export($server + array('REQUEST_METHOD' => $method, 'SERVER_NAME' => 'cacti.example'), true) . ';
		$_REQUEST = array("action" => ' . var_export($action, true) . ');
		$_SESSION = array("sess_user_id" => 5);
		$GLOBALS["ran"] = "";
		register_shutdown_function(function () {
			print $GLOBALS["ran"] !== "" ? "ran:" . $GLOBALS["ran"] : (string) http_response_code();
		});
		' . $switch;

	$file = tempnam(sys_get_temp_dir(), 'tree');
	file_put_contents($file, $script);

	try {
		return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
	} finally {
		unlink($file);
	}
}

/**
 * @return array<string, string> Action => the handler it runs.
 */
function mutations() {
	return array(
		'actions'         => 'form_actions',
		'sortasc'         => 'tree_sort_name_asc',
		'sortdesc'        => 'tree_sort_name_desc',
		'tree_up'         => 'tree_up',
		'tree_down'       => 'tree_down',
		'ajax_dnd'        => 'tree_dnd',
		'lock'            => 'api_tree_lock',
		'unlock'          => 'api_tree_unlock',
		'copy_node'       => 'api_tree_copy_node',
		'create_node'     => 'api_tree_create_node',
		'delete_node'     => 'api_tree_delete_node',
		'move_node'       => 'api_tree_move_node',
		'rename_node'     => 'api_tree_rename_node',
		'set_host_sort'   => 'set_host_sort_type',
		'set_branch_sort' => 'set_branch_sort_type',
	);
}

test('tree mutations refuse a GET from anywhere, including the same site', function () {
	$wrong = array();

	foreach (array_keys(mutations()) as $action) {
		foreach (array(array(), array('HTTP_SEC_FETCH_SITE' => 'same-origin'), array('HTTP_SEC_FETCH_SITE' => 'cross-site')) as $server) {
			$result = run_tree('GET', $action, $server);

			if ($result !== '405') {
				$wrong[] = "$action GET " . json_encode($server) . ": $result";
			}
		}

		if (($result = run_tree('HEAD', $action)) !== '405') {
			$wrong[] = "$action HEAD: $result";
		}
	}

	expect($wrong)->toBe(array());
});

test('tree mutations still run on POST', function () {
	$wrong = array();

	foreach (mutations() as $action => $handler) {
		if (($result = run_tree('POST', $action, array('HTTP_SEC_FETCH_SITE' => 'same-origin'))) !== 'ran:' . $handler) {
			$wrong[] = "$action: $result";
		}
	}

	expect($wrong)->toBe(array());
});

test('tree reads stay available by GET', function () {
	$reads = array(
		'get_node'        => 'api_tree_get_node',
		'get_host_sort'   => 'get_host_sort_type',
		'get_branch_sort' => 'get_branch_sort_type',
		'sites'           => 'display_sites',
		'hosts'           => 'display_hosts',
		'graphs'          => 'display_graphs',
	);

	$wrong = array();

	foreach ($reads as $action => $handler) {
		if (($result = run_tree('GET', $action)) !== 'ran:' . $handler) {
			$wrong[] = "$action: $result";
		}
	}

	expect($wrong)->toBe(array());
});
