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
 * The tree editor offers node and sort changes only to the user holding the
 * tree's lock, but the routes and form_save() never checked the lock.
 */

$root = dirname(__DIR__, 4);

/* Runs the tree.php dispatch as a POST from user 5, who may modify tree 3.
   $lock is tree 3's graph_tree row; branch 11 is in tree 3. */
$runController = function ($action, array $vars, array $lock) use ($root) {
	$program = <<<'PHP'
namespace TreeLockRuntime;

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['sess_user_id']  = 5;
$GLOBALS['vars']           = json_decode($argv[1], true);
$GLOBALS['lock']           = json_decode($argv[2], true);
define('MESSAGE_LEVEL_ERROR', 3);
define('TREE_ORDERING_NONE', 1);

$source = file_get_contents(getcwd() . '/tree.php');

preg_match('/switch \(get_request_var\(\'action\'\)\) \{(?P<body>.*?)^}$/ms', $source, $match);
preg_match('/^function form_save\(\).*?^}\n/ms', $source, $save);
preg_match_all('/^function (tree_require_post|tree_require_access|tree_require_lock|tree_branch_tree_id)\(.*?^}\n/ms', $source, $helpers);
if (empty($match['body']) || empty($save[0])) {
	exit(2);
}

function get_request_var($name) { return isset($GLOBALS['vars'][$name]) ? $GLOBALS['vars'][$name] : ''; }
function get_filter_request_var($name) { return get_request_var($name); }
function get_nfilter_request_var($name) { return get_request_var($name); }
function isset_request_var($name) { return isset($GLOBALS['vars'][$name]); }
function isempty_request_var($name) { return empty($GLOBALS['vars'][$name]); }
function cacti_log($message, $output = false, $facility = '') { echo 'LOG:' . $facility . ':' . $message . "\n"; }
function header($value) { echo 'HEADER:' . $value . "\n"; }
function raise_message($name, $text = '', $level = 0) { echo 'MESSAGE:' . $name . ':' . $text . "\n"; }
function __($text, ...$args) { return $args ? vsprintf($text, $args) : $text; }
function html_escape($text) { return htmlspecialchars($text, ENT_QUOTES); }
function csrf_require_post($strict = false) {}
function get_username($user_id) { return $user_id == 6 ? '<b>other</b>' : 'user' . $user_id; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_authorize_resource($user_id, $resource_id, $resource_type) { return $resource_id === 3; }
function db_fetch_cell_prepared($sql, $params = array()) {
	if (strpos($sql, 'graph_tree_items') !== false) {
		return $params[0] == 11 ? 3 : false;
	}

	return 1;
}
function db_fetch_row_prepared($sql, $params = array()) { return $params[0] === 3 ? $GLOBALS['lock'] : array(); }
function form_input_validate($value) { return $value; }
function is_error_message() { return false; }
function sql_save($save, $table) { echo "HANDLER:sql_save\n"; return 3; }
function top_header() {}
function bottom_footer() {}
function tree() {}
function tree_edit($partial = false) {}

$handlers = array(
	'form_actions', 'tree_sort_name_asc', 'tree_sort_name_desc',
	'display_sites', 'display_hosts', 'display_graphs', 'tree_up', 'tree_down', 'tree_dnd',
	'api_tree_lock', 'api_tree_unlock', 'api_tree_copy_node', 'api_tree_create_node',
	'api_tree_delete_node', 'api_tree_move_node', 'api_tree_rename_node', 'api_tree_get_node',
	'get_host_sort_type', 'set_host_sort_type', 'get_branch_sort_type', 'set_branch_sort_type',
	'set_config_option', 'sort_recursive', 'tree_get_max_sequence',
);

foreach ($handlers as $handler) {
	eval('namespace TreeLockRuntime; function ' . $handler . '() { echo "HANDLER:' . $handler . '\n"; }');
}

foreach ($helpers[0] as $helper) {
	eval('namespace TreeLockRuntime; ' . $helper);
}

eval('namespace TreeLockRuntime; ' . $save[0]);
eval("namespace TreeLockRuntime; switch (get_request_var('action')) {" . $match['body'] . '}');
echo 'accepted';
PHP;

	$vars['action'] = $action;

	$process = proc_open(
		array(PHP_BINARY, '-r', $program, json_encode($vars), json_encode($lock)),
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

$heldBySelf  = array('locked' => 1, 'locked_date' => '2026-09-18 10:00:00', 'modified_by' => 5);
$heldByOther = array('locked' => 1, 'locked_date' => '2026-09-18 10:00:00', 'modified_by' => 6);
$unlocked    = array('locked' => 0, 'locked_date' => '2026-09-18 10:00:00', 'modified_by' => 6);

/* action => [handler, request vars for tree 3] */
$edits = array(
	'copy_node'       => array('api_tree_copy_node', array('tree_id' => '3', 'id' => 'thost:9', 'parent' => 'tbranch:11')),
	'create_node'     => array('api_tree_create_node', array('tree_id' => '3', 'id' => 'tbranch:11')),
	'delete_node'     => array('api_tree_delete_node', array('tree_id' => '3', 'id' => 'tbranch:11')),
	'move_node'       => array('api_tree_move_node', array('tree_id' => '3', 'id' => 'tbranch:11', 'parent' => '#')),
	'rename_node'     => array('api_tree_rename_node', array('tree_id' => '3', 'id' => 'tbranch:11', 'text' => 'x')),
	'set_host_sort'   => array('set_host_sort_type', array('nodeid' => 'tbranch:11_thost:9', 'type' => 'hsgt')),
	'set_branch_sort' => array('set_branch_sort_type', array('nodeid' => 'tbranch:11', 'type' => 'alpha')),
);

$otherMessage = 'MESSAGE:tree_locked:This tree has been locked for Editing on 2026-09-18 10:00:00 by &lt;b&gt;other&lt;/b&gt;. To edit the tree, you must first unlock it and then lock it as yourself';
$lockMessage  = 'MESSAGE:tree_locked:To Edit this tree, you must first lock it by pressing the Edit Tree button.';

test('tree edits are refused while another user holds the lock', function () use ($runController, $edits, $heldByOther, $otherMessage) {
	foreach ($edits as $action => $edit) {
		list($exit, $stdout, $stderr) = $runController($action, $edit[1], $heldByOther);

		expect($exit)->toBe(0, $stderr)
			->and($stdout)->toContain($otherMessage)
			->and($stdout)->toContain('LOG:AUTH:WARNING: Rejected tree.php?action=' . $action . ' on Tree 3 without its lock for User 5')
			->and($stdout)->toContain('HEADER:Location: tree.php?header=false')
			->and($stdout)->not->toContain('HANDLER:');
	}
});

test('tree edits are refused until the user locks the tree', function () use ($runController, $edits, $unlocked, $lockMessage) {
	foreach ($edits as $action => $edit) {
		list($exit, $stdout, $stderr) = $runController($action, $edit[1], $unlocked);

		expect($exit)->toBe(0, $stderr)
			->and($stdout)->toContain($lockMessage)
			->and($stdout)->not->toContain('HANDLER:');
	}
});

test('tree edits run for the user holding the lock', function () use ($runController, $edits, $heldBySelf) {
	foreach ($edits as $action => $edit) {
		list($exit, $stdout, $stderr) = $runController($action, $edit[1], $heldBySelf);

		expect($exit)->toBe(0, $stderr)
			->and($stdout)->toContain('HANDLER:' . $edit[0])
			->and($stdout)->not->toContain('MESSAGE:tree_locked');
	}
});

test('a tree locked by another user can not be locked over or saved', function () use ($runController, $heldByOther, $otherMessage) {
	list($exit, $stdout, $stderr) = $runController('lock', array('id' => '3'), $heldByOther);

	expect($exit)->toBe(0, $stderr)
		->and($stdout)->toContain($otherMessage)
		->and($stdout)->not->toContain('HANDLER:');

	list($exit, $stdout, $stderr) = $runController('save', array('id' => '3', 'save_component_tree' => '1', 'name' => 'x', 'sort_type' => '1'), $heldByOther);

	expect($exit)->toBe(0, $stderr)
		->and($stdout)->toContain($otherMessage)
		->and($stdout)->not->toContain('HANDLER:sql_save');
});

test('an unlocked or self-held tree can be locked', function () use ($runController, $heldBySelf, $unlocked) {
	foreach (array($heldBySelf, $unlocked) as $lock) {
		list($exit, $stdout, $stderr) = $runController('lock', array('id' => '3'), $lock);

		expect($exit)->toBe(0, $stderr)
			->and($stdout)->toContain('HANDLER:api_tree_lock')
			->and($stdout)->not->toContain('MESSAGE:tree_locked');
	}
});

test('an existing tree is saved only by the user holding its lock', function () use ($runController, $heldBySelf, $unlocked, $lockMessage) {
	$save = array('id' => '3', 'save_component_tree' => '1', 'name' => 'x', 'sort_type' => '1');

	list($exit, $stdout, $stderr) = $runController('save', $save, $heldBySelf);

	expect($exit)->toBe(0, $stderr)
		->and($stdout)->toContain('HANDLER:sql_save')
		->and($stdout)->not->toContain('MESSAGE:tree_locked');

	list($exit, $stdout, $stderr) = $runController('save', $save, $unlocked);

	expect($exit)->toBe(0, $stderr)
		->and($stdout)->toContain($lockMessage)
		->and($stdout)->not->toContain('HANDLER:sql_save');
});

test('the tree owner can release a lock another user holds, as the editor allows', function () use ($runController, $heldByOther) {
	list($exit, $stdout, $stderr) = $runController('unlock', array('id' => '3'), $heldByOther);

	expect($exit)->toBe(0, $stderr)
		->and($stdout)->toContain('HANDLER:api_tree_unlock')
		->and($stdout)->not->toContain('MESSAGE:tree_locked');
});
