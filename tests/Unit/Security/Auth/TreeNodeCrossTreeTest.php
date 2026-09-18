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
 * api_tree_move_node() renumbered the siblings under the new parent in every
 * tree, and a move, copy or create could name a parent from another tree.
 */

$root = dirname(__DIR__, 4);

/* Runs lib/api_tree.php's node functions against an in-memory SQLite copy of
   two trees and returns graph_tree_items afterwards, keyed by id. Tree 1 holds
   root branches 10 and 11; tree 2 holds root branches 20 and 21 and item 22
   under 20. */
$runTree = function ($call) use ($root) {
	$program = <<<'PHP'
namespace TreeCrossTreeRuntime;

define('TREE_ORDERING_INHERIT', 1);
define('TREE_ORDERING_NONE', 2);
define('TREE_ORDERING_ALPHABETIC', 3);
define('TREE_ORDERING_NATURAL', 4);
define('TREE_ORDERING_NUMERIC', 5);

$GLOBALS['db'] = new \PDO('sqlite::memory:');
$GLOBALS['db']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$GLOBALS['db']->exec('CREATE TABLE graph_tree (id INTEGER PRIMARY KEY, sort_type INTEGER)');
$GLOBALS['db']->exec('CREATE TABLE graph_tree_items (id INTEGER PRIMARY KEY, graph_tree_id INTEGER, parent INTEGER, position INTEGER,
	title TEXT, local_graph_id INTEGER DEFAULT 0, host_id INTEGER DEFAULT 0, site_id INTEGER DEFAULT 0,
	host_grouping_type INTEGER DEFAULT 1, sort_children_type INTEGER DEFAULT 1)');
$GLOBALS['db']->exec('CREATE TABLE sites (id INTEGER PRIMARY KEY, name TEXT)');
$GLOBALS['db']->exec('CREATE TABLE host (id INTEGER PRIMARY KEY, description TEXT)');
$GLOBALS['db']->exec('CREATE TABLE graph_templates_graph (local_graph_id INTEGER, title_cache TEXT)');
$GLOBALS['db']->exec('INSERT INTO host VALUES (5, "device")');
$GLOBALS['db']->exec('INSERT INTO graph_tree VALUES (1, 2), (2, 2)');
$GLOBALS['db']->exec("INSERT INTO graph_tree_items (id, graph_tree_id, parent, position, title) VALUES
	(10, 1, 0, 1, 'a1'), (11, 1, 0, 2, 'a2'), (20, 2, 0, 1, 'b1'), (21, 2, 0, 2, 'b2'), (22, 2, 20, 1, 'b3')");

function run($sql, $params) { $q = $GLOBALS['db']->prepare($sql); $q->execute(array_values($params)); return $q; }
function db_fetch_assoc_prepared($sql, $params = array()) { return run($sql, $params)->fetchAll(\PDO::FETCH_ASSOC); }
function db_fetch_row_prepared($sql, $params = array()) { $row = run($sql, $params)->fetch(\PDO::FETCH_ASSOC); return $row === false ? array() : $row; }
function db_fetch_cell_prepared($sql, $params = array()) { return run($sql, $params)->fetchColumn(); }
function db_execute_prepared($sql, $params = array()) { run($sql, $params); return true; }
function sql_save($save, $table) {
	run('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($save)) . ') VALUES (' . implode(', ', array_fill(0, count($save), '?')) . ')', $save);

	return (int) $GLOBALS['db']->lastInsertId();
}
function array_rekey($array, $key, $value) { $out = array(); foreach ($array as $row) { $out[$row[$key]] = $row[$value]; } return $out; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function input_validate_input_number($value) { if (!is_numeric($value)) { exit('invalid'); } }
function cacti_log($message, $output = false, $facility = '') { echo 'LOG:' . $message . "\n"; }
function set_config_option($name, $value) {}
function __($text) { return $text; }
function is_tree_allowed($tree_id) { return true; }

$source = file_get_contents(getcwd() . '/lib/api_tree.php');
preg_match_all('/^function (api_tree_[a-z_]+)\(.*?^}\n/ms', $source, $functions);
foreach ($functions[0] as $function) {
	eval('namespace TreeCrossTreeRuntime; ' . $function);
}

$call = json_decode($argv[1], true);
ob_start();
call_user_func_array(__NAMESPACE__ . '\\' . $call[0], $call[1]);
echo ob_get_clean() . "\n";

$items = array();
foreach (db_fetch_assoc_prepared('SELECT id, graph_tree_id, parent, position FROM graph_tree_items ORDER BY id') as $row) {
	$items[$row['id']] = array_map('intval', $row);
}
echo 'ITEMS:' . json_encode($items);
PHP;

	$process = proc_open(
		array(PHP_BINARY, '-r', $program, json_encode($call)),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes,
		$root
	);

	expect(is_resource($process))->toBeTrue();

	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	expect(proc_close($process))->toBe(0, $stderr . $stdout);
	expect($stdout)->toContain('ITEMS:');

	return array(json_decode(substr($stdout, strpos($stdout, 'ITEMS:') + 6), true), $stdout);
};

$treeB = array(
	20 => array('id' => 20, 'graph_tree_id' => 2, 'parent' => 0, 'position' => 1),
	21 => array('id' => 21, 'graph_tree_id' => 2, 'parent' => 0, 'position' => 2),
	22 => array('id' => 22, 'graph_tree_id' => 2, 'parent' => 20, 'position' => 1),
);

$inTree = function ($items, $tree_id) {
	return array_filter($items, function ($item) use ($tree_id) {
		return $item['graph_tree_id'] === $tree_id;
	});
};

test('moving a branch within one tree leaves another tree\'s positions alone', function () use ($runTree, $treeB, $inTree) {
	list($items) = $runTree(array('api_tree_move_node', array(1, 'tbranch:11', '#', 0)));

	expect($inTree($items, 2))->toBe($treeB)
		->and($items[11]['position'])->toBe(1)
		->and($items[10]['position'])->toBe(2);
});

test('a move under a parent in another tree is refused', function () use ($runTree, $treeB, $inTree) {
	list($items, $stdout) = $runTree(array('api_tree_move_node', array(1, 'tbranch:11', 'tbranch:20', 0)));

	expect($stdout)->toContain("ERROR: Branch '11' or Parent '20' is not in TreeID: '1', Function move_node")
		->and($items[11])->toBe(array('id' => 11, 'graph_tree_id' => 1, 'parent' => 0, 'position' => 2))
		->and($inTree($items, 2))->toBe($treeB);
});

test('a move of a branch from another tree is refused', function () use ($runTree, $treeB, $inTree) {
	list($items, $stdout) = $runTree(array('api_tree_move_node', array(1, 'tbranch:22', 'tbranch:10', 0)));

	expect($stdout)->toContain('is not in TreeID')
		->and($inTree($items, 2))->toBe($treeB);
});

test('a move under a parent in the same tree still works', function () use ($runTree, $treeB, $inTree) {
	list($items) = $runTree(array('api_tree_move_node', array(1, 'tbranch:11', 'tbranch:10', 0)));

	expect($items[11]['parent'])->toBe(10)
		->and($inTree($items, 2))->toBe($treeB);
});

test('creating or copying a node under a parent in another tree is refused', function () use ($runTree) {
	foreach (array(
		array('api_tree_create_node', array(1, 'tbranch:20', 0, 'x')),
		array('api_tree_copy_node', array(1, 'thost:5', 'tbranch:20', 0)),
	) as $call) {
		list($items, $stdout) = $runTree($call);

		expect($stdout)->toContain("ERROR: Parent '20' is not in TreeID: '1'")
			->and(count($items))->toBe(5);
	}

	foreach (array(
		array('api_tree_create_node', array(1, 'tbranch:10', 0, 'x')),
		array('api_tree_copy_node', array(1, 'thost:5', 'tbranch:10', 0)),
	) as $call) {
		list($items) = $runTree($call);

		expect(count($items))->toBe(6)
			->and(end($items)['graph_tree_id'])->toBe(1)
			->and(end($items)['parent'])->toBe(10);
	}
});
