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
			if (strpos($s, "SELECT step") !== false || strpos($s, "SELECT heartbeat") !== false) {
				return 300;
			}

			if (strpos($s, "FROM data_source_profiles_rra") !== false) {
				return (isset($GLOBALS["rras"][$p[0]]) && (string) $GLOBALS["rras"][$p[0]] === (string) $p[1]) ? 1 : 0;
			}

			$u = isset($GLOBALS["usage"][$p[0]]) ? $GLOBALS["usage"][$p[0]] : array(0, 0);

			return strpos($s, "local_data_id > 0") !== false ? $u[1] : $u[0] + $u[1];
		}
		function header($h) { print "HEADER:" . $h . "\n"; }
		function set_request_var($n, $v) { $_REQUEST[$n] = $v; }
		function form_input_validate($v, $n, $r, $a, $e) { return $v; }
		function input_validate_input_number($v) {}
		function is_error_message() { return false; }
		function get_hash_data_source_profile($i) { return "hash"; }
		function sql_save($s, $t) { print "SAVE:" . $t . ":" . json_encode($s) . "\n"; return 11; }
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

test('RRA removal refuses any GET, an RRA of another profile and a read only profile', function () {
	expect_refused('item_remove', array('id' => '7', 'profile_id' => '3'));

	foreach (array(array('3', array('7' => 9)), array('0', array('7' => 3)), array('3', array())) as $case) {
		$output = run_profiles('POST', 'item_remove', array('id' => '7', 'profile_id' => $case[0]), array(), array('profile_item_remove'), array(), $case[1]);

		expect($output)->toContain('LOG:WEBUI:WARNING: Refused to remove RRA 7 outside Data Source Profile ' . $case[0] . ' for user 5')
			->and($output)->not->toContain('EXEC:');
	}

	$output = run_profiles('POST', 'item_remove', array('id' => '7', 'profile_id' => '3'), array(), array('profile_item_remove'), array('3' => array(0, 1)), array('7' => 3));

	expect($output)->toContain('LOG:WEBUI:WARNING: Refused to remove RRA 7 from read only Data Source Profile 3 for user 5')
		->and($output)->toContain('MESSAGE:profile_read_only')
		->and($output)->not->toContain('EXEC:');

	/* Data Templates alone leave the RRAs editable, as the edit page does. */
	$output = run_profiles('POST', 'item_remove', array('id' => '7', 'profile_id' => '3'), array(), array('profile_item_remove'), array('3' => array(2, 0)), array('7' => 3));

	expect($output)->toBe("EXEC:DELETE FROM data_source_profiles_rra WHERE id = ? AND data_source_profile_id = ? [\"7\",\"3\"]\nCODE:200\n");
});

test('the profile edit page posts RRA removal with the token and the profile id', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/data_source_profiles.php');

	expect(preg_match('/^function profile_edit\(\).*?^}\R/ms', $source, $edit))->toBe(1)
		->and($edit[0])->toContain("\$.post('data_source_profiles.php?action=item_remove', {")
		->and($edit[0])->toMatch('/__csrf_magic: csrfMagicToken,\s+id: \$\(\'#rra_id\'\)\.val\(\),\s+profile_id: profile_id\s+}\)/');
});

test('saving a read only profile refuses the fields its edit page disables', function () {
	$save = array('form_save', 'profile_is_read_only', 'profile_refuse_read_only');

	foreach (array(array('step' => '300', 'heartbeat' => '600'), array('x_files_factor' => '0.5'), array('consolidation_function_id' => array('1'))) as $locked) {
		$output = run_profiles('POST', 'save', array('save_component_profile' => '1', 'id' => '3', 'name' => 'p') + $locked, array(), $save, array('3' => array(0, 1)));

		expect($output)->toContain('LOG:WEBUI:WARNING: Refused to change the step, X-Files Factor or Consolidation Functions of read only Data Source Profile 3 for user 5')
			->and($output)->toContain('MESSAGE:profile_read_only')
			->and($output)->toContain('HEADER:Location: data_source_profiles.php?header=false&action=edit&id=3')
			->and($output)->not->toContain('SAVE:')
			->and($output)->not->toContain('EXEC:');
	}

	$output = run_profiles('POST', 'save', array('save_component_profile' => '1', 'id' => '3', 'name' => 'renamed', 'heartbeat' => '300'), array(), $save, array('3' => array(0, 1)));

	expect($output)->toContain('SAVE:data_source_profiles:{"id":"3","hash":"hash","name":"renamed"}')
		->and($output)->not->toContain('Refused');

	/* Data Templates alone leave the profile editable, as the edit page does. */
	$output = run_profiles('POST', 'save', array('save_component_profile' => '1', 'id' => '3', 'name' => 'p', 'step' => '60', 'heartbeat' => '120', 'x_files_factor' => '0.5'), array(), $save, array('3' => array(2, 0)));

	expect($output)->toContain('SAVE:data_source_profiles:{"id":"3","hash":"hash","name":"p","step":"60","heartbeat":"120","x_files_factor":"0.5"}')
		->and($output)->not->toContain('Refused');
});

test('saving an RRA refuses one of another profile, and a new or resized RRA of a read only profile', function () {
	$save = array('form_save', 'profile_is_read_only', 'profile_refuse_read_only');

	$output = run_profiles('POST', 'save', array('save_component_rra' => '1', 'id' => '7', 'profile_id' => '4', 'name' => 'r', 'timespan' => '86400'), array(), $save, array(), array('7' => 3));

	expect($output)->toContain('LOG:WEBUI:WARNING: Refused to save RRA 7 outside Data Source Profile 4 for user 5')
		->and($output)->not->toContain('SAVE:');

	foreach (array(array('id' => '0', 'steps' => '300', 'rows' => '600'), array('id' => '7', 'steps' => '600'), array('id' => '7', 'rows' => '900')) as $locked) {
		$output = run_profiles('POST', 'save', array('save_component_rra' => '1', 'profile_id' => '3', 'name' => 'r', 'timespan' => '86400') + $locked, array(), $save, array('3' => array(0, 1)), array('7' => 3));

		expect($output)->toContain('LOG:WEBUI:WARNING: Refused to change the RRAs of read only Data Source Profile 3 for user 5')
			->and($output)->toContain('MESSAGE:profile_read_only')
			->and($output)->toContain('HEADER:Location: data_source_profiles.php?header=false&action=edit&id=3')
			->and($output)->not->toContain('SAVE:');
	}

	$output = run_profiles('POST', 'save', array('save_component_rra' => '1', 'id' => '7', 'profile_id' => '3', 'name' => 'r', 'timespan' => '86400'), array(), $save, array('3' => array(0, 1)), array('7' => 3));

	expect($output)->toContain('SAVE:data_source_profiles_rra:{"id":"7","name":"r","data_source_profile_id":"3","timespan":"86400"}')
		->and($output)->not->toContain('Refused');

	$output = run_profiles('POST', 'save', array('save_component_rra' => '1', 'id' => '0', 'profile_id' => '3', 'name' => 'r', 'timespan' => '86400', 'steps' => '600', 'rows' => '700'), array(), $save, array('3' => array(2, 0)));

	expect($output)->toContain('SAVE:data_source_profiles_rra:{"id":"0","name":"r","data_source_profile_id":"3","timespan":"86400","steps":2,"rows":"700"}')
		->and($output)->not->toContain('Refused');
});
