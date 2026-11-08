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
*/

/*
 * Deleting a graph removes it from the aggregates that list it as a member.
 * api_aggregate_disassociate() is keyed by the aggregate's graph id, so the
 * removal must look that id up instead of passing the aggregate_graphs row id.
 */

namespace AggregateMemberRemoval;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/api_graph.php'), 'api_graph_remove_aggregate_items'));
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/aggregate.php'), 'api_aggregate_disassociate'));

function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function array_rekey($rows, $key, $value) {
	$keyed = array();

	foreach ((array) $rows as $row) {
		$keyed[$row[$key]] = $row[$value];
	}

	return $keyed;
}
function set_config_option($name, $value) {}
function push_out_aggregates($template_id, $local_graph_id = 0) { $GLOBALS['am_pushed'][] = array($template_id, $local_graph_id); }
function db_fetch_assoc_prepared($sql, $params = array()) {
	$statement = $GLOBALS['am_db']->prepare($sql);
	$statement->execute($params);

	return $statement->fetchAll(\PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $params = array()) {
	$statement = $GLOBALS['am_db']->prepare($sql);
	$statement->execute($params);

	return $statement->fetchColumn();
}
function db_execute_prepared($sql, $params = array()) {
	return $GLOBALS['am_db']->prepare($sql)->execute($params);
}

function am_members($aggregate_graph_id) {
	return $GLOBALS['am_db']->query("SELECT local_graph_id FROM aggregate_graphs_items WHERE aggregate_graph_id = $aggregate_graph_id ORDER BY local_graph_id")->fetchAll(\PDO::FETCH_COLUMN);
}

beforeEach(function () {
	$db = new \PDO('sqlite::memory:');
	$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
	$db->exec('CREATE TABLE aggregate_graphs(id INTEGER PRIMARY KEY, local_graph_id INTEGER, aggregate_template_id INTEGER)');
	$db->exec('CREATE TABLE aggregate_graphs_items(aggregate_graph_id INTEGER, local_graph_id INTEGER, sequence INTEGER)');
	$GLOBALS['am_db']     = $db;
	$GLOBALS['am_pushed'] = array();
});

test('a deleted member leaves an aggregate whose row id differs from its graph id', function () {
	// Aggregate row 1 is the graph 7; aggregate row 2 is the graph 8.
	$GLOBALS['am_db']->exec('INSERT INTO aggregate_graphs VALUES (1, 7, 0), (2, 8, 3)');
	$GLOBALS['am_db']->exec('INSERT INTO aggregate_graphs_items VALUES (1, 5, 1), (1, 6, 2), (2, 5, 1), (2, 9, 2)');

	api_graph_remove_aggregate_items(array(5));

	expect(am_members(1))->toBe(array(6));
	expect(am_members(2))->toBe(array(9));
	expect($GLOBALS['am_pushed'])->toBe(array(array(0, 7), array(3, 8)));
});

test('the removal works when the aggregate row id equals the id of another graph', function () {
	// Row 6 of aggregate_graphs is the graph 9, while graph 6 is a member of row 1 (graph 7).
	$GLOBALS['am_db']->exec('INSERT INTO aggregate_graphs VALUES (1, 7, 0), (6, 9, 0)');
	$GLOBALS['am_db']->exec('INSERT INTO aggregate_graphs_items VALUES (1, 5, 1), (1, 6, 2), (6, 5, 1), (6, 6, 2)');

	api_graph_remove_aggregate_items(array(5));

	expect(am_members(1))->toBe(array(6));
	expect(am_members(6))->toBe(array(6));
});

test('the comma separated id list api_graph_remove_multi() builds removes every member', function () {
	$GLOBALS['am_db']->exec('INSERT INTO aggregate_graphs VALUES (1, 7, 0)');
	$GLOBALS['am_db']->exec('INSERT INTO aggregate_graphs_items VALUES (1, 5, 1), (1, 6, 2), (1, 9, 3)');

	api_graph_remove_aggregate_items('5, 6');

	expect(am_members(1))->toBe(array(9));
});

test('a graph that belongs to no aggregate changes nothing', function () {
	$GLOBALS['am_db']->exec('INSERT INTO aggregate_graphs VALUES (1, 7, 0)');
	$GLOBALS['am_db']->exec('INSERT INTO aggregate_graphs_items VALUES (1, 6, 1)');

	api_graph_remove_aggregate_items(array(5));

	expect(am_members(1))->toBe(array(6));
	expect($GLOBALS['am_pushed'])->toBe(array());
});
