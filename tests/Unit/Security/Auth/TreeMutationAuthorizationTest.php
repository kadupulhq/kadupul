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
 * form_save() and form_actions() check tree ownership, but the lock, node,
 * sort type and reorder routes reached api_tree with at most the view check
 * in is_tree_allowed().
 */

$root = dirname(__DIR__, 4);

/* Runs the tree.php dispatch as a POST from user 5, who may modify only the
   trees listed in $allowed. Trees 3 and 4 exist; branch 11 is in tree 3 and
   branch 12 in tree 4. */
$runController = function ($action, array $vars, array $allowed) use ($root) {
	$program = <<<'PHP'
namespace TreeAuthorizationRuntime;

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['sess_user_id']  = 5;
$GLOBALS['vars']           = json_decode($argv[1], true);
$GLOBALS['allowed']        = json_decode($argv[2], true);
define('MESSAGE_LEVEL_ERROR', 3);

$source = file_get_contents(getcwd() . '/tree.php');

preg_match('/switch \(get_request_var\(\'action\'\)\) \{(?P<body>.*?)^}$/ms', $source, $match);
preg_match_all('/^function (tree_require_post|tree_require_access|tree_branch_tree_id)\(.*?^}\n/ms', $source, $helpers);
if (empty($match['body'])) {
	exit(2);
}

function get_request_var($name) { return isset($GLOBALS['vars'][$name]) ? $GLOBALS['vars'][$name] : ''; }
function get_filter_request_var($name) { return get_request_var($name); }
function get_nfilter_request_var($name) { return get_request_var($name); }
function isset_request_var($name) { return isset($GLOBALS['vars'][$name]); }
function cacti_log($message, $output = false, $facility = '') { echo 'LOG:' . $facility . ':' . $message . "\n"; }
function header($value) { echo 'HEADER:' . $value . "\n"; }
function raise_message($name, $text = '', $level = 0) { echo 'MESSAGE:' . $name . ':' . $text . "\n"; }
function __($text) { return $text; }
function csrf_require_post($strict = false) {}
function cacti_authorize_resource($user_id, $resource_id, $resource_type) {
	echo 'AUTHZ:' . $user_id . ':' . $resource_id . ':' . $resource_type . "\n";

	return $user_id === 5 && $resource_type === 'graph_tree' && in_array($resource_id, $GLOBALS['allowed'], true);
}
function db_fetch_assoc($sql) { return array(array('id' => 3), array('id' => 4)); }
function db_fetch_cell_prepared($sql, $params = array()) {
	$branches = array(11 => 3, 12 => 4);

	return isset($branches[$params[0]]) ? $branches[$params[0]] : false;
}
function top_header() {}
function bottom_footer() {}
function tree() {}
function tree_edit($partial = false) {}

$handlers = array(
	'form_save', 'form_actions', 'tree_sort_name_asc', 'tree_sort_name_desc',
	'display_sites', 'display_hosts', 'display_graphs', 'tree_up', 'tree_down', 'tree_dnd',
	'api_tree_lock', 'api_tree_unlock', 'api_tree_copy_node', 'api_tree_create_node',
	'api_tree_delete_node', 'api_tree_move_node', 'api_tree_rename_node', 'api_tree_get_node',
	'get_host_sort_type', 'set_host_sort_type', 'get_branch_sort_type', 'set_branch_sort_type',
);

foreach ($handlers as $handler) {
	eval('namespace TreeAuthorizationRuntime; function ' . $handler . '() { echo "HANDLER:' . $handler . '\n"; }');
}

foreach ($helpers[0] as $helper) {
	eval('namespace TreeAuthorizationRuntime; ' . $helper);
}

eval("namespace TreeAuthorizationRuntime; switch (get_request_var('action')) {" . $match['body'] . '}');
echo 'accepted';
PHP;

	$vars['action'] = $action;

	$process = proc_open(
		array(PHP_BINARY, '-r', $program, json_encode($vars), json_encode($allowed)),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes,
		$root
	);

	expect(is_resource($process))->toBeTrue();

	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	return array(proc_close($process), $stdout, $stderr);
};

/* action => [handler, request vars aimed at tree 3, the same aimed at tree 4] */
$routes = array(
	'lock'            => array('api_tree_lock', array('id' => '3'), array('id' => '4')),
	'unlock'          => array('api_tree_unlock', array('id' => '3'), array('id' => '4')),
	'copy_node'       => array('api_tree_copy_node', array('tree_id' => '3', 'id' => 'thost:9', 'parent' => 'tbranch:11'), array('tree_id' => '4', 'id' => 'thost:9', 'parent' => 'tbranch:12')),
	'create_node'     => array('api_tree_create_node', array('tree_id' => '3', 'id' => 'tbranch:11'), array('tree_id' => '4', 'id' => 'tbranch:12')),
	'delete_node'     => array('api_tree_delete_node', array('tree_id' => '3', 'id' => 'tbranch:11'), array('tree_id' => '4', 'id' => 'tbranch:12')),
	'move_node'       => array('api_tree_move_node', array('tree_id' => '3', 'id' => 'tbranch:11', 'parent' => '#'), array('tree_id' => '4', 'id' => 'tbranch:12', 'parent' => '#')),
	'rename_node'     => array('api_tree_rename_node', array('tree_id' => '3', 'id' => 'tbranch:11', 'text' => 'x'), array('tree_id' => '4', 'id' => 'tbranch:12', 'text' => 'x')),
	'set_host_sort'   => array('set_host_sort_type', array('nodeid' => 'tbranch:11_thost:9', 'type' => 'hsgt'), array('nodeid' => 'tbranch:12_thost:9', 'type' => 'hsgt')),
	'set_branch_sort' => array('set_branch_sort_type', array('nodeid' => 'tbranch:11', 'type' => 'alpha'), array('nodeid' => 'tbranch:12', 'type' => 'alpha')),
	'tree_up'         => array('tree_up', array('id' => '3'), array('id' => '4')),
	'tree_down'       => array('tree_down', array('id' => '3'), array('id' => '4')),
	'ajax_dnd'        => array('tree_dnd', array('tree_ids' => array('line3')), array('tree_ids' => array('line3', 'line4'))),
);

test('tree mutations are refused for a tree the user may not modify', function () use ($runController, $routes) {
	foreach ($routes as $action => $route) {
		list($exit, $stdout, $stderr) = $runController($action, $route[2], array(3));

		expect($exit)->toBe(0, $stderr)
			->and($stdout)->toContain('AUTHZ:5:4:graph_tree')
			->and($stdout)->toContain('LOG:AUTH:WARNING: Rejected tree.php?action=' . $action . ' on Tree 4 for User 5')
			->and($stdout)->toContain('MESSAGE:tree_idor:You do not have permission to modify this tree.')
			->and($stdout)->toContain('HEADER:Location: tree.php?header=false')
			->and($stdout)->not->toContain('HANDLER:')
			->and($stdout)->not->toContain('accepted');
	}
});

test('tree mutations still run for a tree the user may modify', function () use ($runController, $routes) {
	foreach ($routes as $action => $route) {
		list($exit, $stdout, $stderr) = $runController($action, $route[1], array(3));

		expect($exit)->toBe(0, $stderr)
			->and($stdout)->toContain('AUTHZ:5:3:graph_tree')
			->and($stdout)->toContain('HANDLER:' . $route[0])
			->and($stdout)->not->toContain('MESSAGE:tree_idor');
	}
});

test('a sort type change on an unknown branch is refused', function () use ($runController) {
	foreach (array('set_host_sort', 'set_branch_sort') as $action) {
		list($exit, $stdout, $stderr) = $runController($action, array('nodeid' => 'tbranch:99', 'type' => 'alpha'), array(3, 4));

		expect($exit)->toBe(0, $stderr)
			->and($stdout)->toContain('AUTHZ:5:0:graph_tree')
			->and($stdout)->not->toContain('HANDLER:');
	}
});

test('sorting every tree by name needs access to every tree', function () use ($runController) {
	foreach (array('sortasc' => 'tree_sort_name_asc', 'sortdesc' => 'tree_sort_name_desc') as $action => $handler) {
		list($exit, $stdout, $stderr) = $runController($action, array(), array(3));

		expect($exit)->toBe(0, $stderr)
			->and($stdout)->toContain('MESSAGE:tree_idor')
			->and($stdout)->not->toContain('HANDLER:');

		list($exit, $stdout, $stderr) = $runController($action, array(), array(3, 4));

		expect($exit)->toBe(0, $stderr)
			->and($stdout)->toContain('HANDLER:' . $handler);
	}
});
