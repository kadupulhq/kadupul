<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

/*
 * tree_up() and tree_down() swap the requested tree with the tree at the
 * adjacent sequence. The dispatch checks only the requested tree, so the
 * handlers must also check the neighbour they move.
 */

$root = dirname(__DIR__, 4);

/* Runs tree_up() or tree_down() for tree 3 at sequence 5 as user 5. */
$runMove = function ($handler, array $allowed) use ($root) {
	$program = <<<'PHP'
namespace TreeNeighbourRuntime;

$_SESSION['sess_user_id'] = 5;
$GLOBALS['allowed']       = json_decode($argv[2], true);
define('MESSAGE_LEVEL_ERROR', 3);

$source = file_get_contents(getcwd() . '/tree.php');

preg_match_all('/^function (tree_up|tree_down|tree_require_access)\(.*?^}\n/ms', $source, $functions);

function get_filter_request_var($name) { return '3'; }
function tree_check_sequences() {}
function cacti_log($message, $output = false, $facility = '') { echo 'LOG:' . $message . "\n"; }
function raise_message($name, $text = '', $level = 0) { echo 'MESSAGE:' . $name . "\n"; }
function header($value) { echo 'HEADER:' . $value . "\n"; }
function __($text) { return $text; }
function cacti_authorize_resource($user_id, $resource_id, $resource_type) { return in_array($resource_id, $GLOBALS['allowed'], true); }
function db_fetch_cell_prepared($sql, $params = array()) { return 5; }
function db_fetch_assoc_prepared($sql, $params = array()) {
	echo 'NEIGHBOUR:' . $params[0] . "\n";
	return array(array('id' => $params[0] == 4 ? '8' : '9'));
}
function db_execute_prepared($sql, $params = array()) { echo 'UPDATE:' . implode(',', $params) . "\n"; }

foreach ($functions[0] as $function) {
	eval('namespace TreeNeighbourRuntime; ' . $function);
}

call_user_func(__NAMESPACE__ . '\\' . $argv[1]);
PHP;

	$process = proc_open(
		array(PHP_BINARY, '-r', $program, $handler, json_encode($allowed)),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes,
		$root
	);

	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	return array(proc_close($process), $stdout, $stderr);
};

test('moving a tree is refused when the neighbour it swaps with is not the caller\'s', function () use ($runMove) {
	foreach (array('tree_up' => '8', 'tree_down' => '9') as $handler => $neighbour) {
		list($exit, $stdout, $stderr) = $runMove($handler, array(3));

		expect($exit)->toBe(0, $stderr)
			->and($stdout)->toContain('LOG:WARNING: Rejected tree.php?action=' . $handler . ' on Tree ' . $neighbour . ' for User 5')
			->and($stdout)->toContain('MESSAGE:tree_idor')
			->and($stdout)->not->toContain('UPDATE:');
	}
});

test('moving a tree swaps both rows when the caller may modify both trees', function () use ($runMove) {
	foreach (array('tree_up' => array('4', array(3, 8)), 'tree_down' => array('6', array(3, 9))) as $handler => $case) {
		list($exit, $stdout, $stderr) = $runMove($handler, $case[1]);

		expect($exit)->toBe(0, $stderr)
			->and($stdout)->toContain('NEIGHBOUR:' . $case[0])
			->and($stdout)->toContain('UPDATE:5,' . $case[0])
			->and($stdout)->toContain('UPDATE:' . $case[0] . ',3')
			->and($stdout)->not->toContain('MESSAGE:');
	}
});
