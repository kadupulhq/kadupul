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
 * #1078: the CDEF delete action refuses CDEFs that graphs, aggregates or other
 * CDEFs still reference, as the list's Deletable column already says.
 */

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

function cdef_delete_run($selected, $stale_check = false) {
	$root    = dirname(__DIR__, 2);
	$library = file_get_contents($root . '/lib/cdef.php');
	$page    = file_get_contents($root . '/cdef.php');
	expect($library)->toBeString()->and($page)->toBeString();
	$program = 'namespace CdefDeleteProbe;const MESSAGE_LEVEL_ERROR=3;$messages=array();$cdef_actions=array();'
		. '$db=new \PDO("sqlite::memory:",null,null,array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC));'
		. 'foreach (array("CREATE TABLE cdef (id INTEGER PRIMARY KEY, name TEXT)","CREATE TABLE cdef_items (id INTEGER PRIMARY KEY, cdef_id INTEGER, type INTEGER, value TEXT)",'
		. '"CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, cdef_id INTEGER)","CREATE TABLE aggregate_graph_templates_item (cdef_id INTEGER)","CREATE TABLE aggregate_graphs_graph_item (cdef_id INTEGER)",'
		. '"INSERT INTO cdef VALUES (1,\'graphed\'),(2,\'unused\'),(3,\'parent\'),(4,\'child\'),(5,\'aggregated\')",'
		. '"INSERT INTO cdef_items VALUES (1,1,4,\'CURRENT_DATA_SOURCE\'),(2,2,4,\'x\'),(3,3,5,\'4\'),(4,4,4,\'y\'),(5,5,4,\'z\')",'
		. '"INSERT INTO graph_templates_item VALUES (1,1)","INSERT INTO aggregate_graphs_graph_item VALUES (5)") as $sql) { $db->exec($sql); }'
		. '$stale_check=' . var_export($stale_check, true) . ';'
		. 'function db_fetch_assoc_prepared($s,$p=array()){if($GLOBALS["stale_check"]){return array();}$q=$GLOBALS["db"]->prepare($s);$q->execute($p);return $q->fetchAll();}'
		. 'function db_execute_prepared($s,$p=array()){$q=$GLOBALS["db"]->prepare($s);return $q->execute($p);}'
		. 'function cacti_sizeof($a){return is_countable($a)?count($a):0;}function cacti_count($a){return cacti_sizeof($a);}'
		. 'function get_filter_request_var(...$a){return "1";}function get_nfilter_request_var($n){return $n==="drp_action"?"1":$GLOBALS["selected"];}'
		. 'function isset_request_var($n){return $n==="selected_items";}function sanitize_unserialize_selected_items($v){return $v;}'
		. 'function raise_message($id,$m,$l){$GLOBALS["messages"][]=$id;}function __($t){return $t;}function header($h){}'
		. '$selected=' . var_export($selected, true) . ';'
		. test_php_function_source($library, 'cdef_is_in_use') . test_php_function_source($page, 'form_actions')
		. 'register_shutdown_function(function(){echo json_encode(array(array_column($GLOBALS["db"]->query("SELECT id FROM cdef ORDER BY id")->fetchAll(),"id"),'
		. 'array_column($GLOBALS["db"]->query("SELECT DISTINCT cdef_id FROM cdef_items ORDER BY cdef_id")->fetchAll(),"cdef_id"),$GLOBALS["messages"]));});'
		. 'form_actions();';
	$process = proc_open(array(PHP_BINARY, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	expect(is_resource($process))->toBeTrue();
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	expect(proc_close($process))->toBe(0, $err . $out)->and($err)->toBe('');

	return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

test('a CDEF nothing references is deleted with its items', function () {
	expect(cdef_delete_run(array('2')))->toBe(array(array(1, 3, 4, 5), array(1, 3, 4, 5), array()));
});

test('a referenced CDEF is refused and nothing in the selection is deleted', function ($selected) {
	expect(cdef_delete_run($selected))->toBe(array(array(1, 2, 3, 4, 5), array(1, 2, 3, 4, 5), array('cdef_in_use')));
})->with(array(
	'used by a graph'               => array(array('1')),
	'used by an aggregate'          => array(array('5')),
	'used by another CDEF'          => array(array('4')),
	'mixed with an unused CDEF'     => array(array('2', '1')),
));

test('a CDEF referenced only by a CDEF deleted with it can go', function () {
	expect(cdef_delete_run(array('3', '4')))->toBe(array(array(1, 2, 5), array(1, 2, 5), array()));
});

test('a reference the check did not see still keeps the CDEF', function ($selected) {
	// The check reads nothing, as if each reference was added after it ran.
	expect(cdef_delete_run($selected, true))->toBe(array(array(1, 2, 3, 4, 5), array(1, 2, 3, 4, 5), array()));
})->with(array(
	'used by a graph'      => array(array('1')),
	'used by an aggregate' => array(array('5')),
	'used by another CDEF' => array(array('4')),
));
