<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

require_once dirname(__DIR__, 4) . '/lib/api_aggregate.php';
define('POLLER_VERBOSITY_DEBUG', 6);

function cacti_log($message, $output = false, $category = '', $verbosity = 0) {
}

function db_execute_prepared($sql, $parameters = array(), $log = true, $connection = false) {
	$GLOBALS['aggregate_queries'][] = array($sql, $parameters);
	if (!empty($GLOBALS['aggregate_fail_insert']) && str_starts_with($sql, 'INSERT')) {
		return false;
	}
	if (!empty($GLOBALS['aggregate_fail_delete']) && str_starts_with($sql, 'DELETE')) {
		return false;
	}

	return $GLOBALS['aggregate_connection']->prepare($sql)->execute($parameters);
}

function db_execute($sql) {
	return db_execute_prepared($sql);
}

function db_qstr($value) {
	return $value === null ? 'NULL' : $GLOBALS['aggregate_connection']->quote((string) $value);
}

beforeEach(function () {
	$GLOBALS['aggregate_connection'] = new PDO('sqlite::memory:');
	$GLOBALS['aggregate_connection']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$GLOBALS['aggregate_connection']->exec('CREATE TABLE aggregate_graphs_graph_item (
		aggregate_graph_id INTEGER, graph_templates_item_id INTEGER, sequence INTEGER,
		color_template INTEGER, t_graph_type_id TEXT, graph_type_id INTEGER,
		t_cdef_id TEXT, cdef_id INTEGER, item_skip TEXT, item_total TEXT)');
	$GLOBALS['aggregate_connection']->exec('INSERT INTO aggregate_graphs_graph_item VALUES
		(1, 10, 0, 0, \'\', 0, \'\', 0, \'\', \'\'), (2, 20, 0, 0, \'\', 0, \'\', 0, \'\', \'\')');
	$GLOBALS['database_hostname'] = 'test';
	$GLOBALS['database_port'] = '0';
	$GLOBALS['database_default'] = 'test';
	$GLOBALS['database_sessions'] = array('test:0:test' => $GLOBALS['aggregate_connection']);
	$GLOBALS['aggregate_fail_insert'] = false;
	$GLOBALS['aggregate_queries'] = array();
	$GLOBALS['aggregate_fail_delete'] = false;
});

function aggregateSavedRows() {
	return $GLOBALS['aggregate_connection']->query('SELECT * FROM aggregate_graphs_graph_item ORDER BY aggregate_graph_id, graph_templates_item_id')->fetchAll(PDO::FETCH_ASSOC);
}

it('rejects a SQL fragment or malformed parent before changing stored items', function ($parent) {
	$before = aggregateSavedRows();
	expect(aggregate_graph_items_save(array(array('aggregate_graph_id' => $parent, 'graph_templates_item_id' => 11)), 'aggregate_graphs_graph_item'))->toBeFalse();
	expect($GLOBALS['aggregate_queries'])->toBe(array());
	expect(aggregateSavedRows())->toBe($before);
})->with(array_map(function ($value) { return array($value); }, array('1 OR 1=1', '0; DELETE FROM aggregate_graphs_graph_item', array(1), true, null, 0, '-1', '999999999999999999999999999999')));

it('binds every value and preserves other parents while replacing one parent', function () {
	$value = "' OR 1=1 --";
	$items = array(array('aggregate_graph_id' => ' 01 ', 'graph_templates_item_id' => 11, 't_cdef_id' => $value));
	expect(aggregate_graph_items_save($items, 'aggregate_graphs_graph_item'))->toBeTrue();
	$rows = aggregateSavedRows();
	expect($rows[0]['aggregate_graph_id'])->toBe(1);
	expect($rows[0]['graph_templates_item_id'])->toBe(11);
	expect($rows[0]['t_cdef_id'])->toBe($value);
	expect($rows[1]['aggregate_graph_id'])->toBe(2);
	expect($rows[1]['graph_templates_item_id'])->toBe(20);
	expect($GLOBALS['aggregate_queries'][0])->toBe(array('DELETE FROM aggregate_graphs_graph_item WHERE aggregate_graph_id = ?', array(1)));
});

it('rejects mixed parents and structured field values before deleting anything', function () {
	$before = aggregateSavedRows();
	foreach (array(
		array(array('aggregate_graph_id' => 1, 'graph_templates_item_id' => 11), array('aggregate_graph_id' => 2, 'graph_templates_item_id' => 21)),
		array(array('aggregate_graph_id' => 1, 'graph_templates_item_id' => 11, 'item_skip' => array('on'))),
		array(), array('invalid row')
	) as $items) {
		expect(aggregate_graph_items_save($items, 'aggregate_graphs_graph_item'))->toBeFalse();
	}
	expect($GLOBALS['aggregate_queries'])->toBe(array());
	expect(aggregateSavedRows())->toBe($before);
});

it('stops replacement when the delete fails', function () {
	$before = aggregateSavedRows();
	$GLOBALS['aggregate_fail_delete'] = true;
	expect(aggregate_graph_items_save(array(array('aggregate_graph_id' => 1, 'graph_templates_item_id' => 11)), 'aggregate_graphs_graph_item'))->toBeFalse();
	expect($GLOBALS['aggregate_queries'])->toHaveCount(1);
	expect(aggregateSavedRows())->toBe($before);
});

it('rolls back the delete if the insert fails', function () {
	$before = aggregateSavedRows();
	$GLOBALS['aggregate_fail_insert'] = true;
	expect(aggregate_graph_items_save(array(array('aggregate_graph_id' => 1, 'graph_templates_item_id' => 11)), 'aggregate_graphs_graph_item'))->toBeFalse();
	expect(aggregateSavedRows())->toBe($before);
	expect($GLOBALS['aggregate_connection']->inTransaction())->toBeFalse();
});

it('rolls back only its own replacement inside a caller transaction', function () {
	$connection = $GLOBALS['aggregate_connection'];
	$connection->beginTransaction();
	$connection->exec("INSERT INTO aggregate_graphs_graph_item VALUES (3, 30, 0, 0, '', 0, '', 0, '', '')");
	$before = aggregateSavedRows();
	$GLOBALS['aggregate_fail_insert'] = true;
	expect(aggregate_graph_items_save(array(array('aggregate_graph_id' => 1, 'graph_templates_item_id' => 11)), 'aggregate_graphs_graph_item'))->toBeFalse();
	expect($connection->inTransaction())->toBeTrue();
	expect(aggregateSavedRows())->toBe($before);
	$connection->rollBack();
});

it('leaves successful writes uncommitted when the caller owns the transaction', function () {
	$connection = $GLOBALS['aggregate_connection'];
	$before = aggregateSavedRows();
	$connection->beginTransaction();
	expect(aggregate_graph_items_save(array(array('aggregate_graph_id' => 1, 'graph_templates_item_id' => 11)), 'aggregate_graphs_graph_item'))->toBeTrue();
	expect($connection->inTransaction())->toBeTrue();
	$connection->rollBack();
	expect(aggregateSavedRows())->toBe($before);
});

it('bounds prepared batches while replacing a large parent', function () {
	$items = array();
	for ($id = 100; $id < 7100; $id++) {
		$items[] = array('aggregate_graph_id' => 1, 'graph_templates_item_id' => $id);
	}
	expect(aggregate_graph_items_save($items, 'aggregate_graphs_graph_item'))->toBeTrue();
	expect(aggregateSavedRows())->toHaveCount(7001);
	expect($GLOBALS['aggregate_queries'])->toHaveCount(8);
	foreach ($GLOBALS['aggregate_queries'] as $query) {
		expect(count($query[1]))->toBeLessThanOrEqual(10000);
	}
});
