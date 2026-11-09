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

$sf_source = file_get_contents(dirname(__DIR__, 3) . '/lib/data_query.php');
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($sf_source, 'get_best_data_query_index_type'));
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($sf_source, 'run_data_query_work'));
eval('namespace ' . __NAMESPACE__ . '; use PDO;' . str_replace('__DIR__', var_export(dirname(__DIR__, 3) . '/lib', true), \test_php_function_source($sf_source, 'data_query_reindex_local')));
function data_query_transaction($host, $tables, $work) { return $work($GLOBALS['sf_db']); }

const HOST_DOWN = 1;
const DATA_INPUT_TYPE_SNMP_QUERY = 3;
const DATA_INPUT_TYPE_SCRIPT_QUERY = 4;
const DATA_INPUT_TYPE_QUERY_SCRIPT_SERVER = 6;

function read_config_option($name) { return ''; }
function db_column_exists($table, $column) { return true; }
function query_debug_timer_start() {}
function query_debug_timer_offset($section, $message) {}
function __esc($text, ...$args) { return $text; }
function query_snmp_host($host_id, $snmp_query_id) { return true; }
function update_data_query_sort_cache($host_id, $snmp_query_id) {
	throw new \RuntimeException('the sort cache was rewritten after a failed sort field read');
}

function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_log(...$args) { $GLOBALS['sf_logs'][] = $args[0]; }
function get_data_query_array($data_query_id) { $GLOBALS['sf_xml_reads']++; return $GLOBALS['sf_xml']; }
function db_fetch_row_prepared($sql, $params = array()) {
	if ($GLOBALS['sf_read_fails'] && strpos($sql, 'sort_field') !== false) {
		return false;
	}

	$statement = $GLOBALS['sf_db']->prepare($sql);
	$statement->execute($params);
	$row = $statement->fetch(\PDO::FETCH_ASSOC);

	return $row === false ? array() : $row;
}
// The cell reader the function used before it read the row: false on failure, the first column otherwise.
function db_fetch_cell_prepared($sql, $params = array()) {
	if (strpos($sql, 'data_input.type_id') !== false) {
		return DATA_INPUT_TYPE_SNMP_QUERY;
	}

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
	$db->exec('CREATE TABLE host(id INTEGER, status INTEGER, disabled TEXT, poller_id INTEGER)');
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

test('a re-index stops before rewriting the sort cache when the stored sort field can not be read', function () {
	$library = sys_get_temp_dir() . '/sort-field-library-' . getmypid();
	expect(is_dir($library) || mkdir($library))->toBeTrue();
	foreach (array('poller.php', 'api_data_source.php', 'utility.php') as $file) {
		expect(file_put_contents($library . '/' . $file, '<?php '))->toBe(6);
	}
	$GLOBALS['config']      = array('poller_id' => 1, 'library_path' => $library);
	$GLOBALS['input_types'] = array();
	$GLOBALS['sf_db']->exec("INSERT INTO host VALUES (1, 3, '', 1)");
	$GLOBALS['sf_db']->exec("INSERT INTO host_snmp_query VALUES (1, 2, 'ifName')");
	$GLOBALS['sf_read_fails'] = true;

	try {
		$result = run_data_query_work(1, 2);
	} finally {
		foreach (array('poller.php', 'api_data_source.php', 'utility.php') as $file) {
			unlink($library . '/' . $file);
		}
		rmdir($library);
	}

	expect($result)->toBeFalse();
	expect(sf_stored())->toBe(array('ifName'));
	expect(implode("\n", $GLOBALS['sf_logs']))->toContain('current sort field for Device[1] and DQ[2] could not be read');
});
