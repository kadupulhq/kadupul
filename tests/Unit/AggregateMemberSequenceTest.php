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
 * #226: rebuilding an aggregate graph passes its members to
 * aggregate_create_update() in their stored sequence, so No Reordering keeps
 * the order the operator chose. The production rebuild runs against SQLite
 * with the aggregate tables it reads.
 */

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

function aggregate_member_rebuild($members) {
	$root      = dirname(__DIR__, 2);
	$aggregate = file_get_contents($root . '/lib/api_aggregate.php');
	$functions = file_get_contents($root . '/lib/functions.php');
	expect($aggregate)->toBeString()->and($functions)->toBeString();
	$program = 'namespace AggregateSequenceProbe;$calls=array();'
		. '$db=new \PDO("sqlite::memory:",null,null,array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC));'
		. '$db->exec("CREATE TABLE aggregate_graphs (id INTEGER PRIMARY KEY, local_graph_id INTEGER, title_format TEXT, graph_template_id INTEGER, gprint_prefix TEXT, gprint_format TEXT, graph_type INTEGER, total INTEGER, total_type INTEGER, total_prefix TEXT, order_type INTEGER)");'
		. '$db->exec("CREATE TABLE aggregate_graphs_graph_item (aggregate_graph_id INTEGER, sequence INTEGER, item_skip TEXT, item_total TEXT, color_template INTEGER, t_graph_type_id TEXT, graph_type_id INTEGER, t_cdef_id TEXT, cdef_id INTEGER)");'
		. '$db->exec("CREATE TABLE aggregate_graphs_items (aggregate_graph_id INTEGER NOT NULL, local_graph_id INTEGER NOT NULL, sequence INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (aggregate_graph_id, local_graph_id))");'
		. '$db->exec("CREATE TABLE graph_templates_item (local_graph_id INTEGER)");'
		. '$db->exec("INSERT INTO aggregate_graphs VALUES (3, 90, \'Aggregate\', 1, \'\', \'1\', 0, 0, 0, \'\', 1)");'
		. 'foreach (' . var_export($members, true) . ' as list($graph,$sequence)) { $db->prepare("INSERT INTO aggregate_graphs_items VALUES (3, ?, ?)")->execute(array($graph,$sequence)); }'
		. 'function db_fetch_assoc_prepared($sql, $p = array()) { $q=$GLOBALS["db"]->prepare($sql); $q->execute($p); return $q->fetchAll(); }'
		. 'function db_fetch_row_prepared($sql, $p = array()) { $r=db_fetch_assoc_prepared($sql,$p); return isset($r[0]) ? $r[0] : array(); }'
		. 'function db_fetch_cell_prepared($sql, $p = array()) { $r=db_fetch_row_prepared($sql,$p); return $r===array()?false:reset($r); }'
		. 'function db_execute_prepared($sql, $p = array()) { $q=$GLOBALS["db"]->prepare($sql); return $q->execute($p); }'
		. 'function cacti_sizeof($a){return is_countable($a)?count($a):0;}'
		. 'function aggregate_create_update(&$ag,$members,$attribs){ $GLOBALS["calls"][]=array($ag,$members,$attribs["reorder"]); return true; }'
		. test_php_function_source($functions, 'array_rekey')
		. test_php_function_source($aggregate, 'push_out_aggregates')
		. 'push_out_aggregates(0,90);echo json_encode($GLOBALS["calls"]);';
	$process = proc_open(array(PHP_BINARY, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	expect(is_resource($process))->toBeTrue();
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	expect(proc_close($process))->toBe(0, $err . $out);

	return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

test('a rebuild passes the members in their stored sequence', function ($members, $expected) {
	expect(aggregate_member_rebuild($members))->toBe(array(array(90, $expected, 1)));
})->with(array(
	'reversed against graph id'             => array(array(array(5, 2), array(6, 1)), array(6, 5)),
	'three members out of id order'         => array(array(array(5, 3), array(6, 1), array(7, 2)), array(6, 7, 5)),
	'equal sequences fall back to graph id' => array(array(array(8, 0), array(4, 0)), array(4, 8)),
));
