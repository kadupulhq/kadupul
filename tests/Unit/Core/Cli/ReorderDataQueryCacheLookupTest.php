<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace ReorderDataQueryCacheLookupTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function cacti_sizeof($value) {
	return count($value);
}

function array_rekey($input, $key, $value) {
	$result = array();
	foreach ($input as $row) {
		$result[$row[$key]] = $row[$value];
	}

	return $result;
}

function cacti_log(...$arguments) {}

function db_fetch_assoc_prepared($sql, $params) {
	return array(
		array('id' => 1, 'type_code' => 'index_type'),
		array('id' => 2, 'type_code' => 'index_value'),
		array('id' => 3, 'type_code' => 'output_type'),
	);
}

function db_fetch_row_prepared($sql, $params) {
	return $GLOBALS['cache_row_result'];
}

function db_execute_prepared($sql, $params) {
	$GLOBALS['cache_writes'][] = array($sql, $params);

	return true;
}

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/data_query.php');
if (!is_string($source)) {
	throw new \RuntimeException('Cannot read lib/data_query.php.');
}
eval('namespace ReorderDataQueryCacheLookupTest; ' . test_php_function_source($source, 'update_snmp_index_order'));

function run_reorder_with_cache_result($row) {
	$GLOBALS['cache_row_result'] = $row;
	$GLOBALS['cache_writes'] = array();

	return update_snmp_index_order(array(
		'host_id' => 10,
		'snmp_query_id' => 20,
		'snmp_index_on' => 'ifIndex',
		'snmp_index' => '7',
		'data_template_data_id' => 30,
		'snmp_query_graph_id' => 40,
	));
}

test('reorder distinguishes a failed cache query from a missing cache row', function () {
	expect(run_reorder_with_cache_result(false))->toBeFalse()
		->and($GLOBALS['cache_writes'])->toBeEmpty();

	expect(run_reorder_with_cache_result(array()))->toBeTrue()
		->and($GLOBALS['cache_writes'])->toBeEmpty();

	expect(run_reorder_with_cache_result(array('field_value' => 'eth0')))->toBeTrue()
		->and($GLOBALS['cache_writes'])->toHaveCount(1);
});
