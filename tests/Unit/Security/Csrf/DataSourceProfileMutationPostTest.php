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
 * the data_source_profiles.php bulk actions and item_remove, so a same-site
 * link or embed could still run them. The page now refuses every non-POST
 * request for them itself, and checks on the server what the list and the
 * edit page only hide.
 */

namespace DataSourceProfileMutationPostTest;

/**
 * Runs the data_source_profiles.php dispatch switch in a child process with the
 * real include/csrf.php method helpers. The functions named in $real run as
 * written; every other handler is a stub that reports it was reached.
 *
 * @param string                $method  The request method.
 * @param string                $action  The action.
 * @param array<string, string> $request Other request variables.
 * @param array<string, string> $server  Request headers as $_SERVER keys.
 * @param array<int, string>    $real    Page functions to run as written.
 * @param array<string, array>  $usage   Profile id => Data Template and Data Source counts.
 * @param array<string, int>    $rras    RRA id => the profile that owns it.
 *
 * @return string What the handlers printed, then the response code.
 */
function run_profiles($method, $action, array $request = array(), array $server = array(), array $real = array(), array $usage = array(), array $rras = array()) {
	$root      = dirname(__DIR__, 4);
	$csrf      = file_get_contents($root . '/include/csrf.php');
	$source    = file_get_contents($root . '/data_source_profiles.php');
	$functions = '';

	foreach (array('csrf_require_post', 'csrf_request_is_cross_site', 'csrf_request_host_matches', 'csrf_strip_host_port') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $csrf, $matches) === 1) {
			$functions .= $matches[0];
		}
	}

	foreach ($real as $name) {
		expect(preg_match('/^function ' . $name . '\(.*?^}\R/ms', $source, $matches))->toBe(1, $name);
		$functions .= $matches[0];
	}

	expect(preg_match('/^switch \(get_request_var\(\'action\'\)\) \{(.*?)^}$/ms', $source, $switch))->toBe(1);

	$stubs = '';
	foreach (array(
		'form_save' => 'save', 'form_actions' => 'actions', 'profile_item_remove_confirm' => 'item_remove_confirm',
		'profile_item_remove' => 'item_remove', 'item_edit' => 'item_edit', 'profile_edit' => 'edit', 'profile' => 'list',
		'duplicate_data_source_profile' => 'duplicate', 'get_span' => 'ajax_span', 'get_size' => 'ajax_size',
	) as $function => $name) {
		if (!in_array($function, $real, true)) {
			$stubs .= 'function ' . $function . '($a = null, $b = null, $c = null, $d = null) { print "HANDLER:' . $name . '\n"; }' . "\n";
		}
	}

	/* The namespace lets header() be a stub that prints. */
	$script = '<?php
		namespace DataSourceProfileRuntime;

		const MESSAGE_LEVEL_ERROR = 3;
		function get_request_var($v) { return isset($_REQUEST[$v]) ? $_REQUEST[$v] : ""; }
		function get_filter_request_var($v, $f = null, $o = array()) { return get_request_var($v); }
		function get_nfilter_request_var($v) { return get_request_var($v); }
		function isset_request_var($v) { return isset($_REQUEST[$v]); }
		function cacti_log($m, $o = false, $f = "") { print "LOG:" . $f . ":" . $m . "\n"; }
		function raise_message($n, $m = "", $l = 0) { print "MESSAGE:" . $n . "\n"; }
		function __($t) { return $t; }
		function sanitize_unserialize_selected_items($i) { return unserialize($i, array("allowed_classes" => false)); }
		function array_to_sql_or($a, $c) { return "(" . $c . " IN(" . implode(",", $a) . "))"; }
		function db_execute($s) { print "EXEC:" . $s . "\n"; }
		function db_execute_prepared($s, $p = array()) { print "EXEC:" . preg_replace("/\s+/", " ", $s) . " " . json_encode($p) . "\n"; }
		function db_fetch_cell_prepared($s, $p = array()) {
			if (strpos($s, "FROM data_source_profiles_rra") !== false) {
				return (isset($GLOBALS["rras"][$p[0]]) && (string) $GLOBALS["rras"][$p[0]] === (string) $p[1]) ? 1 : 0;
			}

			$u = isset($GLOBALS["usage"][$p[0]]) ? $GLOBALS["usage"][$p[0]] : array(0, 0);

			return strpos($s, "local_data_id > 0") !== false ? $u[1] : $u[0] + $u[1];
		}
		function header($h) { print "HEADER:" . $h . "\n"; }
		function top_header() {}
		function bottom_footer() {}
		' . $stubs . $functions . '
		$_SERVER  = ' . var_export($server + array('REQUEST_METHOD' => $method, 'SERVER_NAME' => 'cacti.example'), true) . ';
		$_REQUEST = ' . var_export(array('action' => $action) + $request, true) . ';
		$_SESSION = array("sess_user_id" => 5);
		$usage    = ' . var_export($usage, true) . ';
		$rras     = ' . var_export($rras, true) . ';
		register_shutdown_function(function () {
			/* The CLI reports no code until one is set. */
			print "CODE:" . (http_response_code() ?: 200) . "\n";
		});
		switch (get_request_var(\'action\')) {' . $switch[1] . '}';

	$file = tempnam(sys_get_temp_dir(), 'dsp');
	file_put_contents($file, $script);

	try {
		return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
	} finally {
		unlink($file);
	}
}

/**
 * Asserts that no request other than a POST reaches the action, including a
 * GET the browser marks as same-origin.
 *
 * @param string                $action  The action.
 * @param array<string, string> $request Other request variables.
 *
 * @return void
 */
function expect_refused($action, array $request) {
	foreach (array(
		array('GET', array()),
		array('GET', array('HTTP_SEC_FETCH_SITE' => 'same-origin')),
		array('GET', array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
		array('HEAD', array()),
	) as $case) {
		$output = run_profiles($case[0], $action, $request, $case[1]);

		expect($output)->toContain("CODE:405\n")
			->and($output)->not->toContain('HANDLER:')
			->and($output)->not->toContain('EXEC:');
	}
}

test('profile bulk actions refuse any GET that carries selected_items', function () {
	foreach (array('1', '2') as $drp_action) {
		expect_refused('actions', array('selected_items' => 'a:1:{i:0;i:3;}', 'drp_action' => $drp_action, 'title_format' => '<profile_title> (1)'));
	}

	expect(run_profiles('POST', 'actions', array('selected_items' => 'a:1:{i:0;i:3;}', 'drp_action' => '1')))->toBe("HANDLER:actions\nCODE:200\n")
		->and(run_profiles('GET', 'actions', array('drp_action' => '1'), array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe("HANDLER:actions\nCODE:200\n");
});

test('deleting profiles skips every profile a Data Template or a Data Source uses', function () {
	$usage  = array('3' => array(1, 0), '4' => array(0, 2), '6' => array(0, 0));
	$output = run_profiles('POST', 'actions', array('selected_items' => 'a:3:{i:0;i:3;i:1;i:4;i:2;i:6;}', 'drp_action' => '1'), array(), array('form_actions', 'profiles_not_in_use'), $usage);

	expect($output)->toContain('LOG:WEBUI:WARNING: Refused to delete Data Source Profile 3 in use by Data Templates or Data Sources for user 5')
		->and($output)->toContain('LOG:WEBUI:WARNING: Refused to delete Data Source Profile 4 in use by Data Templates or Data Sources for user 5')
		->and($output)->toContain('MESSAGE:profile_in_use')
		->and($output)->toContain("EXEC:DELETE FROM data_source_profiles WHERE (id IN(6))\n")
		->and($output)->toContain("EXEC:DELETE FROM data_source_profiles_rra WHERE (data_source_profile_id IN(6))\n")
		->and($output)->toContain("EXEC:DELETE FROM data_source_profiles_cf WHERE (data_source_profile_id IN(6))\n")
		->and(substr_count($output, 'EXEC:'))->toBe(3);

	$output = run_profiles('POST', 'actions', array('selected_items' => 'a:2:{i:0;i:3;i:1;i:4;}', 'drp_action' => '1'), array(), array('form_actions', 'profiles_not_in_use'), $usage);

	expect($output)->toContain('MESSAGE:profile_in_use')
		->and($output)->not->toContain('EXEC:');

	$output = run_profiles('POST', 'actions', array('selected_items' => 'a:1:{i:0;i:3;}', 'drp_action' => '2', 'title_format' => '<profile_title> (1)'), array(), array('form_actions', 'profiles_not_in_use'), $usage);

	expect($output)->toContain('HANDLER:duplicate')
		->and($output)->not->toContain('Refused');
});
