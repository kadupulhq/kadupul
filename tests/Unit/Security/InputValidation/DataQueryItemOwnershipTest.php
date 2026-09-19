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

/*
 * The data_queries.php move and remove handlers took the row id alone, so a
 * request could reorder or delete a suggested value or graph template
 * association under another data query. They now check the parent ids first.
 */

namespace DataQueryItemOwnershipTest;

/**
 * Runs one production data_queries.php handler in a child process with the
 * database stubbed. The ownership lookup answers $owned, no graph uses the
 * row, and every statement is recorded.
 *
 * @param string                $handler The handler function.
 * @param array<string, string> $request The request variables.
 * @param bool                  $owned   What the ownership lookup answers.
 *
 * @return array<string, array> The recorded calls, log lines and messages.
 */
function run_handler($handler, array $request, $owned) {
	$source    = file_get_contents(dirname(__DIR__, 4) . '/data_queries.php');
	$functions = '';

	/* A handler that does not check ownership still runs, so the test shows
	   what it changes rather than only that the check is missing. */
	foreach (array('data_query_request_owns', $handler) as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $source, $matches) === 1) {
			$functions .= $matches[0];
		}
	}

	expect($functions)->toContain('function ' . $handler . '(');

	$script = '<?php
		define("MESSAGE_LEVEL_ERROR", 3);
		$calls = array(); $logs = array(); $messages = array();
		function get_request_var($v, $d = "") { return isset($_REQUEST[$v]) ? $_REQUEST[$v] : $d; }
		function get_filter_request_var($v) { return get_request_var($v); }
		function get_nfilter_request_var($v, $d = "") { return get_request_var($v, $d); }
		function db_fetch_cell_prepared($sql, $params = array()) {
			if (strpos($sql, "graph_local") !== false) {
				$GLOBALS["calls"][] = array("graphs", $sql, $params);
				return "0";
			}
			$GLOBALS["calls"][] = array("lookup", $sql, $params);
			return ' . ($owned ? '"1"' : '"0"') . ';
		}
		function db_execute_prepared($sql, $params = array()) { $GLOBALS["calls"][] = array("execute", $sql, $params); return true; }
		function move_item_up($table, $id, $group) { $GLOBALS["calls"][] = array("move_up", $table, array($id, $group)); }
		function move_item_down($table, $id, $group) { $GLOBALS["calls"][] = array("move_down", $table, array($id, $group)); }
		function cacti_log($m, $o = false, $e = "") { $GLOBALS["logs"][] = $e . ":" . $m; }
		function raise_message($id, $text = "", $level = 0) { $GLOBALS["messages"][] = $id; }
		function __($text) { return $text; }
		' . $functions . '
		$_REQUEST = ' . var_export($request, true) . ';
		' . $handler . '();
		print json_encode(array("calls" => $calls, "logs" => $logs, "messages" => $messages));';

	$file = tempnam(sys_get_temp_dir(), 'dqown');
	file_put_contents($file, $script);

	try {
		$output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
	} finally {
		unlink($file);
	}

	$result = json_decode($output, true);

	expect($result)->toBeArray($output);

	return $result;
}

/**
 * Handler => the request its link sends, the table it checks and the columns
 * that must match the request besides id and snmp_query_id.
 *
 * @return array<string, array>
 */
function owned_handlers() {
	$gsv  = array('id' => '31', 'snmp_query_graph_id' => '4', 'snmp_query_id' => '5', 'field_name' => 'title');
	$dssv = $gsv + array('data_template_id' => '6');

	return array(
		'data_query_item_movedown_gsv'  => array($gsv, 'snmp_query_graph_sv', array('snmp_query_graph_id', 'field_name')),
		'data_query_item_moveup_gsv'    => array($gsv, 'snmp_query_graph_sv', array('snmp_query_graph_id', 'field_name')),
		'data_query_item_remove_gsv'    => array($gsv, 'snmp_query_graph_sv', array('snmp_query_graph_id')),
		'data_query_item_movedown_dssv' => array($dssv, 'snmp_query_graph_rrd_sv', array('snmp_query_graph_id', 'data_template_id', 'field_name')),
		'data_query_item_moveup_dssv'   => array($dssv, 'snmp_query_graph_rrd_sv', array('snmp_query_graph_id', 'data_template_id', 'field_name')),
		'data_query_item_remove_dssv'   => array($dssv, 'snmp_query_graph_rrd_sv', array('snmp_query_graph_id', 'data_template_id')),
		'data_query_item_remove'        => array(array('id' => '4', 'snmp_query_id' => '5'), 'snmp_query_graph', array()),
	);
}

test('a move or delete of a row outside the named data query changes nothing and is logged', function () {
	foreach (owned_handlers() as $handler => $case) {
		list($request, $table) = $case;

		$out = run_handler($handler, $request, false);

		expect(array_column($out['calls'], 0))->toBe(array('lookup'), $handler)
			->and($out['logs'])->toBe(array('WEBUI:WARNING: Refused a change to ' . $table . ' id ' . $request['id'] . ' that is not under Data Query 5'), $handler)
			->and($out['messages'])->toBe(array('data_query_item_mismatch'), $handler);
	}
});

test('the ownership lookup binds the row to the data query, association and field the request names', function () {
	foreach (owned_handlers() as $handler => $case) {
		list($request, $table, $columns) = $case;

		$out = run_handler($handler, $request, false);

		list(, $sql, $params) = $out['calls'][0];

		$expected = array($request['id'], '5');
		foreach ($columns as $column) {
			$expected[] = $request[$column];

			expect($sql)->toContain('AND item.' . $column . ' = ?');
		}

		expect($params)->toBe($expected, $handler)
			->and($sql)->toContain('FROM ' . $table . ' AS item')
			->and($sql)->toContain('WHERE item.id = ?')
			->and($sql)->toContain($table === 'snmp_query_graph' ? 'AND item.snmp_query_id = ?' : 'AND sqg.snmp_query_id = ?');
	}
});

test('a move or delete of a row under the named data query still runs', function () {
	foreach (owned_handlers() as $handler => $case) {
		$out = run_handler($handler, $case[0], true);
		$ran = array_diff(array_column($out['calls'], 0), array('lookup', 'graphs'));

		expect($out['calls'][0][0])->toBe('lookup', $handler)
			->and($ran)->not->toBe(array(), $handler)
			->and($out['logs'])->toBe(array(), $handler)
			->and($out['messages'])->toBe(array(), $handler);
	}
});
