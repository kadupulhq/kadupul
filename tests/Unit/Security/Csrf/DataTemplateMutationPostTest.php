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
 * include/global.php lets a GET the browser does not mark as cross-site reach
 * the data template actions, and the handlers trusted the ids they were sent.
 */

namespace DataTemplateMutationPostTest;

const HELPERS  = 'data_templates_';
const HANDLERS = array('template', 'template_edit', 'template_remove', 'template_rrd_add', 'template_rrd_remove', 'form_save', 'form_actions');
const STUBS    = '
	function db_fetch_cell_prepared($sql, $params = array()) {
		return db("data_sources", $params[0]);
	}
	function db_fetch_row_prepared($sql, $params = array()) {
		event("query:" . implode(",", $params));

		return db("template", $params[0], array());
	}
	function db_fetch_assoc_prepared($sql, $params = array()) {
		$rows = array();

		foreach (db("rrds", $params[0], array()) as $id) {
			$rows[] = array("id" => $id);
		}

		return $rows;
	}
';

/**
 * Runs the dispatch switch of a page, or its form_actions(), in a child
 * process with the real include/csrf.php method helpers, the page's own
 * helpers, and a stub for every handler and database call.
 *
 * @param string                $file    The page.
 * @param string                $method  The request method.
 * @param array<string, mixed>  $request The request variables, action included.
 * @param array<string, mixed>  $db      What the stub database holds.
 * @param array<string, string> $server  Request headers as $_SERVER keys.
 * @param bool                  $actions Run form_actions() instead of the switch.
 *
 * @return string One line per handler, log line, header and write, then the status.
 */
function run_page($file, $method, array $request, array $db = array(), array $server = array(), $actions = false) {
	$root   = dirname(__DIR__, 4);
	$csrf   = file_get_contents($root . '/include/csrf.php');
	$source = file_get_contents($root . '/' . $file);
	$code   = '';

	foreach (array('csrf_require_post', 'csrf_request_is_cross_site', 'csrf_request_host_matches', 'csrf_strip_host_port') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $csrf, $matches) === 1) {
			$code .= $matches[0];
		}
	}

	if (preg_match_all('/^function ' . HELPERS . '\w*\(.*?^}\R/ms', $source, $matches)) {
		$code .= implode('', $matches[0]);
	}

	if ($actions) {
		expect(preg_match('/^function form_actions\(\) \{.*?^}\R/ms', $source, $matches))->toBe(1);

		$code .= $matches[0] . "\nform_actions();\n";
	} else {
		expect(preg_match('/^switch \(get_request_var\(\'action\'\)\) \{(.*?)^}$/ms', $source, $switch))->toBe(1);

		foreach (HANDLERS as $function) {
			$code = 'function ' . $function . '($x = null, $y = null) { event("handler:' . $function . '"); }' . "\n" . $code;
		}

		$code .= "switch (get_request_var('action')) {" . $switch[1] . "}\n";
	}

	$script = '<?php
		namespace ' . __NAMESPACE__ . '\Runtime;

		define("MESSAGE_LEVEL_ERROR", 3);

		$GLOBALS["db"]     = ' . var_export($db, true) . ';
		$GLOBALS["events"] = array();
		$_SERVER  = ' . var_export($server + array('REQUEST_METHOD' => $method, 'SERVER_NAME' => 'cacti.example'), true) . ';
		$_REQUEST = ' . var_export($request, true) . ';

		function event($e) { $GLOBALS["events"][] = $e; }
		function db($key, $id, $default = 0) { return isset($GLOBALS["db"][$key][$id]) ? $GLOBALS["db"][$key][$id] : $default; }
		function __($text) { return $text; }
		function get_request_var($v) { return isset($_REQUEST[$v]) ? $_REQUEST[$v] : ""; }
		function get_filter_request_var($v) { return get_request_var($v); }
		function get_nfilter_request_var($v) { return get_request_var($v); }
		function isset_request_var($v) { return isset($_REQUEST[$v]); }
		function sanitize_unserialize_selected_items($items) { return unserialize($items); }
		function array_to_sql_or($array, $column) { return $column . " IN (" . implode(",", $array) . ")"; }
		function array_rekey($array, $key, $value) { return array(); }
		function cacti_sizeof($array) { return is_array($array) ? count($array) : 0; }
		function cacti_count($array) { return cacti_sizeof($array); }
		function cacti_log($m, $o = false, $f = "") { event("log:" . $f . ":" . $m); }
		function raise_message($name, $message = "", $level = 0) {}
		function header($value) { event("header:" . $value); }
		function http_response_code($code = 0) { event("status:" . $code); return true; }
		function top_header() {}
		function bottom_footer() {}
		function db_execute($sql) { event("write:" . preg_replace("/\s+/", " ", $sql)); }
		function db_execute_prepared($sql, $params = array()) { event("write:" . preg_replace("/\s+/", " ", $sql)); }
		function db_fetch_assoc($sql) { return array(); }
		function db_fetch_cell($sql) { return ""; }
		' . STUBS . '
		register_shutdown_function(function () { print implode("\n", $GLOBALS["events"]); });
		' . $code;

	$tmp = tempnam(sys_get_temp_dir(), 'tpl');
	file_put_contents($tmp, $script);

	try {
		return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1');
	} finally {
		unlink($tmp);
	}
}

/**
 * Asserts that no request other than a POST reaches the action, including a
 * GET the browser marks as same-origin.
 *
 * @param string               $file    The page.
 * @param array<string, mixed> $request The request variables, action included.
 * @param array<string, mixed> $db      What the stub database holds.
 *
 * @return void
 */
function expect_refused($file, array $request, array $db = array()) {
	foreach (array(array('GET', array()), array('GET', array('HTTP_SEC_FETCH_SITE' => 'same-origin')), array('GET', array('HTTP_SEC_FETCH_SITE' => 'cross-site')), array('HEAD', array())) as $case) {
		$output = run_page($file, $case[0], $request, $db, $case[1]);

		expect($output)->toContain('status:405')
			->and($output)->toContain('header:Allow: POST')
			->and($output)->not->toContain('handler:');
	}
}

test('data template bulk actions refuse any GET that carries selected_items', function () {
	foreach (array('1', '2', '3') as $drp_action) {
		expect_refused('data_templates.php', array('action' => 'actions', 'selected_items' => 'a:1:{i:0;i:3;}', 'drp_action' => $drp_action, 'data_source_profile_id' => '1'));
	}

	expect(run_page('data_templates.php', 'POST', array('action' => 'actions', 'selected_items' => 'a:1:{i:0;i:3;}', 'drp_action' => '1')))->toBe('handler:form_actions')
		->and(run_page('data_templates.php', 'GET', array('action' => 'actions', 'drp_action' => '1'), array(), array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('handler:form_actions');
});

test('deleting data templates leaves every template that a data source uses', function () {
	$request = array('action' => 'actions', 'drp_action' => '1', 'selected_items' => serialize(array(3, 4)));
	$output  = run_page('data_templates.php', 'POST', $request, array('data_sources' => array(3 => 2)), array(), true);

	preg_match_all('/^write:.*$/m', $output, $writes);

	expect($writes[0])->not->toBeEmpty()
		->and($output)->toContain('log:AUTH:WARNING: Refused to delete Data Template 3, which 2 Data Source(s) use')
		->and($output)->toContain('header:Location: data_templates.php?header=false');

	foreach ($writes[0] as $write) {
		expect($write)->toContain('IN (4)')
			->and($write)->not->toMatch('/IN \([^)]*\b3\b/');
	}

	$request['selected_items'] = serialize(array(3));

	expect(run_page('data_templates.php', 'POST', $request, array('data_sources' => array(3 => 1)), array(), true))->not->toContain('write:');
});

test('deleting data templates that no data source uses still deletes them', function () {
	$output = run_page('data_templates.php', 'POST', array('action' => 'actions', 'drp_action' => '1', 'selected_items' => serialize(array(4, 5))), array(), array(), true);

	expect($output)->toContain('write:DELETE FROM data_template WHERE id IN (4,5)')
		->and($output)->toContain('write:UPDATE data_local SET data_template_id = 0 WHERE data_template_id IN (4,5)')
		->and($output)->not->toContain('Refused');
});

test('data template item changes refuse any GET, including a same-site one', function () {
	$db = array('template' => array(3 => array('hash' => 'x')), 'rrds' => array(3 => array(7, 8)));

	expect_refused('data_templates.php', array('action' => 'rrd_add', 'id' => '3'), $db);
	expect_refused('data_templates.php', array('action' => 'rrd_remove', 'id' => '7', 'data_template_id' => '3'), $db);
});

test('a data template item is added only to an unused template that does not collect by SNMP Get', function () {
	$refused = array(
		'is not a Data Template'     => array(),
		'is in use by a Data Source' => array('template' => array(3 => array('hash' => 'x')), 'data_sources' => array(3 => 2)),
		'collects by SNMP Get'       => array('template' => array(3 => array('hash' => '3eb92bb845b9660a7445cf9740726522'))),
	);

	foreach ($refused as $reason => $db) {
		$output = run_page('data_templates.php', 'POST', array('action' => 'rrd_add', 'id' => '3'), $db);

		expect($output)->toContain('log:AUTH:WARNING: Rejected data_templates.php?action=rrd_add because Data Template 3 ' . $reason)
			->and($output)->toContain('header:Location: data_templates.php?header=false')
			->and($output)->not->toContain('handler:');
	}

	$output = run_page('data_templates.php', 'POST', array('action' => 'rrd_add', 'id' => '3'), array('template' => array(3 => array('hash' => 'x'))));

	expect($output)->toContain('query:3')
		->and($output)->toContain('handler:template_rrd_add')
		->and($output)->not->toContain('Rejected');
});

test('a data template item is removed only from the unused template that holds it, and never the last one', function () {
	$template = array(3 => array('hash' => 'x'));
	$refused  = array(
		'is not a Data Template'     => array('rrds' => array(3 => array(7, 8))),
		'is in use by a Data Source' => array('template' => $template, 'rrds' => array(3 => array(7, 8)), 'data_sources' => array(3 => 1)),
		'does not hold item 7'       => array('template' => $template, 'rrds' => array(3 => array(8, 9), 4 => array(7, 10))),
		'would lose its last item'   => array('template' => $template, 'rrds' => array(3 => array(7))),
	);

	foreach ($refused as $reason => $db) {
		$output = run_page('data_templates.php', 'POST', array('action' => 'rrd_remove', 'id' => '7', 'data_template_id' => '3'), $db);

		expect($output)->toContain('log:AUTH:WARNING: Rejected data_templates.php?action=rrd_remove because Data Template 3 ' . $reason)
			->and($output)->toContain('header:Location: data_templates.php?header=false')
			->and($output)->not->toContain('handler:');
	}

	$output = run_page('data_templates.php', 'POST', array('action' => 'rrd_remove', 'id' => '7', 'data_template_id' => '3'), array('template' => $template, 'rrds' => array(3 => array(7, 8))));

	expect($output)->toContain('handler:template_rrd_remove')
		->and($output)->not->toContain('Rejected');
});
