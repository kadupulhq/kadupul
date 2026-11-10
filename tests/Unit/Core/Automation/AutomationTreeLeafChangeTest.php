<?php
/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/**
 * Runs automation_change_tree_rule_leaf_type() from lib/api_automation.php in a
 * clean PHP process against SQLite tables with the cacti.sql rule item columns.
 *
 * @param int $leaf the new leaf type for tree rule 8
 *
 * @return array{rules: list<array<string, string>>, items: list<string>, matches: list<string>}
 */
function automation_tree_leaf_change_run($leaf) {
	$root    = dirname(__DIR__, 4);
	$program = test_php_function_source(file_get_contents($root . '/lib/api_automation.php'), 'automation_change_tree_rule_leaf_type') . <<<'PHP'

require $argv[1] . '/include/global_constants.php';
$db = new PDO('sqlite::memory:');
$db->exec("CREATE TABLE automation_tree_rules (id INTEGER PRIMARY KEY, leaf_type INT, name TEXT)");
$db->exec("CREATE TABLE automation_tree_rule_items (id INTEGER PRIMARY KEY, rule_id INT, field TEXT, search_pattern TEXT, replace_pattern TEXT, sequence INT)");
$db->exec("CREATE TABLE automation_match_rule_items (id INTEGER PRIMARY KEY, rule_id INT, rule_type INT, sequence INT, operation INT, field TEXT, operator INT, pattern TEXT)");
$db->exec("INSERT INTO automation_tree_rules VALUES (8, 2, 'Selected'), (9, 2, 'Unrelated')");
$db->exec("INSERT INTO automation_tree_rule_items VALUES (1, 8, 'gtg.title_cache', 'x', 'y', 1), (2, 8, 'gt.name', 'x', 'y', 2), (3, 8, 'h.description', 'x', 'y', 3), (4, 9, 'gt.name', 'x', 'y', 1)");
// Items 5 to 7 are tree rule 8's selection criteria; 8 and 9 belong to graph rule 8, which shares the id; 10 to tree rule 9.
$db->exec("INSERT INTO automation_match_rule_items VALUES
	(5, 8, " . AUTOMATION_RULE_TYPE_TREE_MATCH . ", 1, 0, 'gtg.title_cache', 1, 'x'),
	(6, 8, " . AUTOMATION_RULE_TYPE_TREE_MATCH . ", 2, 0, 'gt.name', 1, 'x'),
	(7, 8, " . AUTOMATION_RULE_TYPE_TREE_MATCH . ", 3, 0, 'h.description', 1, 'x'),
	(8, 8, " . AUTOMATION_RULE_TYPE_GRAPH_MATCH . ", 1, 0, 'gt.name', 1, 'x'),
	(9, 8, " . AUTOMATION_RULE_TYPE_GRAPH_MATCH . ", 2, 0, 'gtg.title_cache', 1, 'x'),
	(10, 9, " . AUTOMATION_RULE_TYPE_TREE_MATCH . ", 1, 0, 'gt.name', 1, 'x')");
function automation_function_with_pid($name) { return $name; }
function cacti_log(...$args) {}
function html_escape($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function db_query($sql, $params) { $statement = $GLOBALS['db']->prepare($sql); $statement->execute($params); return $statement; }
function db_fetch_cell_prepared($sql, $params = array()) { return db_query($sql, $params)->fetchColumn(); }
function db_fetch_assoc_prepared($sql, $params = array()) { return db_query($sql, $params)->fetchAll(PDO::FETCH_ASSOC); }
function db_execute_prepared($sql, $params = array()) { db_query($sql, $params); return true; }
automation_change_tree_rule_leaf_type((int) $argv[2], 8);
echo json_encode(array(
	'rules'   => $db->query('SELECT id, leaf_type FROM automation_tree_rules ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
	'items'   => $db->query('SELECT id FROM automation_tree_rule_items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
	'matches' => $db->query('SELECT id FROM automation_match_rule_items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
));
PHP;
	$process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $root, (string) $leaf), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	$stdout  = stream_get_contents($pipes[1]);
	$stderr  = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');

	return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
}

test('a change to device leaves removes only the tree rule\'s own graph-based criteria', function () {
	$state = automation_tree_leaf_change_run(3); // TREE_ITEM_TYPE_HOST

	expect(array_map('intval', $state['items']))->toBe(array(3, 4))
		// Graph rule 8's criteria (8, 9) and tree rule 9's (10) stay.
		->and(array_map('intval', $state['matches']))->toBe(array(7, 8, 9, 10))
		->and(array_map('intval', array_column($state['rules'], 'leaf_type')))->toBe(array(3, 2));
});

test('a change to graph leaves keeps every criterion', function () {
	$state = automation_tree_leaf_change_run(2); // TREE_ITEM_TYPE_GRAPH

	expect(array_map('intval', $state['items']))->toBe(array(1, 2, 3, 4))
		->and(array_map('intval', $state['matches']))->toBe(array(5, 6, 7, 8, 9, 10));
});
