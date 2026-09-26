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

namespace ChangeDataTemplateFailureTest;

function db_execute_prepared($sql, $params = array()) {
	$GLOBALS['change_template_writes'][] = $sql;

	return stripos($sql, 'REPLACE INTO data_input_data') === 0 ? false : true;
}

function db_fetch_row_prepared($sql, $params = array()) {
	if (stripos($sql, 'local_data_id = 0') !== false) {
		return array('id' => 20);
	}

	return array('id' => 10, 'data_source_path' => '');
}

function db_fetch_cell_prepared($sql, $params = array()) {
	return 0;
}

function db_fetch_assoc_prepared($sql, $params = array()) {
	if (stripos($sql, 'FROM data_input_data') !== false) {
		return array(array('data_input_field_id' => 1, 't_value' => 0, 'value' => 'value'));
	}

	return array();
}

function sql_save($save, $table) {
	return 10;
}

function cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function data_input_field_always_checked($field_id) {
	return false;
}

$source = file_get_contents(dirname(__DIR__, 3) . '/lib/template.php');

if ($source === false || preg_match('/^function change_data_template\(.*?^}\R/ms', $source, $matches) !== 1) {
	throw new \RuntimeException('Unable to extract change_data_template() from lib/template.php');
}

eval('namespace ChangeDataTemplateFailureTest;' . $matches[0]); // nosemgrep: php.lang.security.eval-use.eval-use

test('a failed dependent template write is returned to the datasource CLI for rollback', function () {
	$GLOBALS['struct_data_source']       = array();
	$GLOBALS['struct_data_source_item']  = array();
	$GLOBALS['change_template_writes']   = array();

	expect(change_data_template(5, 7))->toBeFalse()
		->and($GLOBALS['change_template_writes'])->toHaveCount(2)
		->and($GLOBALS['change_template_writes'][1])->toStartWith('REPLACE INTO data_input_data');
});
