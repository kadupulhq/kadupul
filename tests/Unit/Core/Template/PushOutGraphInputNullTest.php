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

namespace PushOutGraphInputNullTest;

/*
 * Several graph_templates_item columns a graph input can select are nullable,
 * such as text_format, value, dashes and dash_offset. 1.2.31 pushed a NULL out
 * to the child items; a stored NULL must not abort the whole push-out.
 */

require_once dirname(__DIR__, 4) . '/lib/graph_template_input.php';

function graph_template_input_column_is_allowed($column_name) {
	return \graph_template_input_column_is_allowed($column_name);
}

function graph_template_input_value_is_allowed($column_name, $value) {
	return \graph_template_input_value_is_allowed($column_name, $value);
}

function cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function array_to_sql_or($array, $sql_column) {
	return '(' . $sql_column . ' IN (' . implode(',', $array) . '))';
}

function cacti_log($message, $output = false, $environ = 'CMDPHP') {
	$GLOBALS['push_out_null_log'][] = $message;
}

function db_fetch_row_prepared($sql, $params = array()) {
	return array('graph_template_id' => 7, 'column_name' => $GLOBALS['push_out_null_column']);
}

function db_fetch_assoc_prepared($sql, $params = array()) {
	return array(array('graph_template_item_id' => 11));
}

function db_fetch_assoc($sql) {
	return $GLOBALS['push_out_null_rows'];
}

function db_execute_prepared($sql, $params = array()) {
	$GLOBALS['push_out_null_updates'][] = $params;

	return true;
}

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/template.php');

if ($source === false || preg_match('/^function push_out_graph_input\(.*?^}\R/ms', $source, $matches) !== 1) {
	throw new \RuntimeException('Unable to extract push_out_graph_input() from lib/template.php');
}

eval('namespace PushOutGraphInputNullTest;' . $matches[0]); // nosemgrep: php.lang.security.eval-use.eval-use

beforeEach(function () {
	$GLOBALS['push_out_null_log']     = array();
	$GLOBALS['push_out_null_updates'] = array();
});

test('a NULL stored value is pushed out instead of aborting', function () {
	$GLOBALS['push_out_null_column'] = 'text_format';
	$GLOBALS['push_out_null_rows']   = array(
		array('local_graph_id' => 1, 'text_format' => null),
		array('local_graph_id' => 2, 'text_format' => 'Inbound'),
	);

	push_out_graph_input(3, 11, array());

	expect($GLOBALS['push_out_null_log'])->toBe(array())
		->and($GLOBALS['push_out_null_updates'])->toBe(array(
			array(null, 1, 11),
			array('Inbound', 2, 11),
		));
});

test('an invalid non-NULL stored value still stops the push-out', function () {
	$GLOBALS['push_out_null_column'] = 'task_item_id';
	$GLOBALS['push_out_null_rows']   = array(
		array('local_graph_id' => 1, 'task_item_id' => null),
		array('local_graph_id' => 2, 'task_item_id' => '1 OR 1=1'),
	);

	push_out_graph_input(3, 11, array());

	expect($GLOBALS['push_out_null_updates'])->toBe(array())
		->and($GLOBALS['push_out_null_log'])->toBe(array('ERROR: push_out_graph_input() refused an invalid graph input value'));
});

test('a column outside the graph input allowlist is still refused', function () {
	$GLOBALS['push_out_null_column'] = 'local_graph_id';
	$GLOBALS['push_out_null_rows']   = array(array('local_graph_id' => 1));

	push_out_graph_input(3, 11, array());

	expect($GLOBALS['push_out_null_updates'])->toBe(array())
		->and($GLOBALS['push_out_null_log'])->toBe(array('ERROR: push_out_graph_input() refused an invalid graph input field'));
});
