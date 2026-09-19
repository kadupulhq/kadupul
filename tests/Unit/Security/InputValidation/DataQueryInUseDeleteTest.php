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
 * data_queries.php hid the delete control for a data query or graph template
 * association that graphs use, but the handlers deleted it anyway when a
 * request named it. They now count the graphs the way the pages do.
 */

namespace DataQueryInUseDeleteTest;

/**
 * Runs a production data_queries.php delete in a child process with the
 * database stubbed. A graph_local count answers $graphs, the ownership lookup
 * answers 1, and every statement is recorded.
 *
 * @param string                $call    The PHP call to run.
 * @param array<string, string> $request The request variables.
 * @param int                   $graphs  What the graph_local count answers.
 *
 * @return array<string, array> The recorded calls, log lines and messages.
 */
function run_delete($call, array $request, $graphs) {
	$source    = file_get_contents(dirname(__DIR__, 4) . '/data_queries.php');
	$functions = '';

	foreach (array('data_query_request_owns', 'data_query_item_remove', 'data_query_remove') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $source, $matches) === 1) {
			$functions .= $matches[0];
		}
	}

	$script = '<?php
		define("MESSAGE_LEVEL_ERROR", 3);
		$calls = array(); $logs = array(); $messages = array();
		function get_request_var($v, $d = "") { return isset($_REQUEST[$v]) ? $_REQUEST[$v] : $d; }
		function get_filter_request_var($v) { return get_request_var($v); }
		function get_nfilter_request_var($v, $d = "") { return get_request_var($v, $d); }
		function db_fetch_cell_prepared($sql, $params = array()) {
			$GLOBALS["calls"][] = array("lookup", $sql, $params);
			return strpos($sql, "graph_local") !== false ? "' . (int) $graphs . '" : "1";
		}
		function db_fetch_assoc_prepared($sql, $params = array()) { $GLOBALS["calls"][] = array("lookup", $sql, $params); return array(array("id" => 4)); }
		function db_execute_prepared($sql, $params = array()) { $GLOBALS["calls"][] = array("execute", $sql, $params); return true; }
		function cacti_sizeof($a) { return is_array($a) ? count($a) : 0; }
		function update_replication_crc($poller_id, $variable) { $GLOBALS["calls"][] = array("crc", $variable, array()); }
		function cacti_log($m, $o = false, $e = "") { $GLOBALS["logs"][] = $e . ":" . $m; }
		function raise_message($id, $text = "", $level = 0) { $GLOBALS["messages"][] = $id; }
		function __($text) { return $text; }
		' . $functions . '
		$_REQUEST = ' . var_export($request, true) . ';
		' . $call . ';
		print json_encode(array("calls" => $calls, "logs" => $logs, "messages" => $messages));';

	$file = tempnam(sys_get_temp_dir(), 'dquse');
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
 * The graph_local count the delete ran, whitespace collapsed.
 *
 * @param array<string, array> $out The recorded run.
 *
 * @return array{0: string, 1: array}
 */
function graph_count(array $out) {
	foreach ($out['calls'] as $call) {
		if ($call[0] === 'lookup' && strpos($call[1], 'graph_local') !== false) {
			return array(preg_replace('/\s+/', ' ', $call[1]), $call[2]);
		}
	}

	return array('', array());
}

test('a data query that graphs use is not deleted and the refusal is logged', function () {
	$out = run_delete('data_query_remove(7)', array(), 2);

	expect(array_column($out['calls'], 0))->not->toContain('execute')
		->and(array_column($out['calls'], 0))->not->toContain('crc')
		->and($out['logs'])->toBe(array('WEBUI:WARNING: Refused to delete Data Query 7 because 2 Graphs use it'))
		->and($out['messages'])->toBe(array('data_query_in_use'));

	/* the list page counts graph_local rows by snmp_query_id */
	expect(graph_count($out))->toBe(array('SELECT COUNT(*) FROM graph_local WHERE snmp_query_id = ?', array(7)));
});

test('a data query that no graph uses is still deleted', function () {
	$out = run_delete('data_query_remove(7)', array(), 0);

	expect(array_column($out['calls'], 0))->toContain('execute')
		->and(array_column($out['calls'], 0))->toContain('crc')
		->and($out['logs'])->toBe(array())
		->and($out['messages'])->toBe(array());
});

test('a graph template association that graphs use is not deleted and the refusal is logged', function () {
	$out = run_delete('data_query_item_remove()', array('id' => '4', 'snmp_query_id' => '5'), 3);

	expect(array_column($out['calls'], 0))->not->toContain('execute')
		->and($out['logs'])->toBe(array('WEBUI:WARNING: Refused to delete Data Query 5 Graph Template association 4 because 3 Graphs use it'))
		->and($out['messages'])->toBe(array('data_query_in_use'));

	/* the edit page counts graph_local rows made from the association and its template */
	expect(graph_count($out))->toBe(array('SELECT COUNT(*) FROM graph_local AS gl INNER JOIN snmp_query_graph AS sqg ON gl.snmp_query_graph_id = sqg.id AND gl.graph_template_id = sqg.graph_template_id WHERE sqg.id = ?', array('4')));
});

test('a graph template association that no graph uses is still deleted', function () {
	$out = run_delete('data_query_item_remove()', array('id' => '4', 'snmp_query_id' => '5'), 0);

	expect(array_column($out['calls'], 0))->toContain('execute')
		->and($out['logs'])->toBe(array())
		->and($out['messages'])->toBe(array());
});
