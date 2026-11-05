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
 * get_best_data_query_index_type() falls back to the data query XML only when
 * the stored sort field is empty.  A failed read of the stored field must not
 * take that fallback, because the fallback writes the XML field back.
 */

namespace SortFieldReadFailure;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 3) . '/lib/data_query.php'), 'get_best_data_query_index_type'));

function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_log(...$args) { $GLOBALS['sf_logs'][] = $args[0]; }
function get_data_query_array($data_query_id) { $GLOBALS['sf_xml_reads']++; return $GLOBALS['sf_xml']; }
function db_fetch_row_prepared($sql, $params = array()) {
	if ($GLOBALS['sf_read_fails']) {
		return false;
	}

	$statement = $GLOBALS['sf_db']->prepare($sql);
	$statement->execute($params);
	$row = $statement->fetch(\PDO::FETCH_ASSOC);

	return $row === false ? array() : $row;
}
// The cell reader the function used before it read the row: false on failure, the first column otherwise.
function db_fetch_cell_prepared($sql, $params = array()) {
	$row = db_fetch_row_prepared($sql, $params);

	return is_array($row) && $row ? reset($row) : false;
}
function db_execute_prepared($sql, $params = array()) {
	$GLOBALS['sf_db']->prepare($sql)->execute($params);

	return true;
}

beforeEach(function () {
	$db = new \PDO('sqlite::memory:');
	$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
	$db->exec('CREATE TABLE host_snmp_query(host_id INTEGER, snmp_query_id INTEGER, sort_field TEXT)');
	$GLOBALS['sf_db']         = $db;
	$GLOBALS['sf_read_fails'] = false;
	$GLOBALS['sf_xml_reads']  = 0;
	$GLOBALS['sf_logs']       = array();
	$GLOBALS['sf_xml']        = array('index_order' => 'ifIndex:ifName', 'fields' => array('ifDescr' => array()));
});

function sf_stored() {
	return $GLOBALS['sf_db']->query('SELECT sort_field FROM host_snmp_query')->fetchAll(\PDO::FETCH_COLUMN);
}

test('a failed read of the stored sort field is reported without reading or storing the XML field', function () {
	$GLOBALS['sf_db']->exec("INSERT INTO host_snmp_query VALUES (1, 2, 'ifName')");
	$GLOBALS['sf_read_fails'] = true;

	expect(get_best_data_query_index_type(1, 2))->toBeFalse();
	expect($GLOBALS['sf_xml_reads'])->toBe(0);
	expect(sf_stored())->toBe(array('ifName'));
});

test('a stored sort field is returned as it is, including the string zero', function ($stored) {
	$GLOBALS['sf_db']->exec("INSERT INTO host_snmp_query VALUES (1, 2, '$stored')");

	expect(get_best_data_query_index_type(1, 2))->toBe($stored);
	expect($GLOBALS['sf_xml_reads'])->toBe(0);
	expect(sf_stored())->toBe(array($stored));
})->with(array('ifName', '0'));

test('an empty or missing stored sort field still takes the XML index order', function ($rows) {
	if ($rows) {
		$GLOBALS['sf_db']->exec("INSERT INTO host_snmp_query VALUES (1, 2, '')");
	}

	expect(get_best_data_query_index_type(1, 2))->toBe('ifIndex');
	expect($GLOBALS['sf_xml_reads'])->toBe(1);
	expect(sf_stored())->toBe($rows ? array('ifIndex') : array());
})->with(array('empty field' => true, 'no row' => false));

test('an empty sort field falls back to the first XML field and reports an unreadable XML', function () {
	$GLOBALS['sf_db']->exec("INSERT INTO host_snmp_query VALUES (1, 2, NULL)");
	$GLOBALS['sf_xml'] = array('fields' => array('ifDescr' => array(), 'ifName' => array()));

	expect(get_best_data_query_index_type(1, 2))->toBe('ifDescr');
	expect(sf_stored())->toBe(array('ifDescr'));

	$GLOBALS['sf_db']->exec("UPDATE host_snmp_query SET sort_field = ''");
	$GLOBALS['sf_xml'] = array();

	expect(get_best_data_query_index_type(1, 2))->toBeFalse();
	expect(sf_stored())->toBe(array(''));
});
