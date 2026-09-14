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
 * The automation, color, data query and RRD check pages gained a
 * csrf_require_post() check after 1.2.31 that refused every GET, including the
 * same-origin links and scripts 1.2.31 accepted. The check now refuses a GET
 * only when the browser marks it as coming from another site. A save, which
 * 1.2.31 already refused without a token, stays refused from anywhere.
 */

namespace CsrfRequirePostCrossSiteTest;

/**
 * Page => action => whether the guard stays strict.
 *
 * @return array<string, array<string, bool>>
 */
function page_guards() {
	return array(
		'automation_devices.php'     => array('purge' => false, 'actions' => false),
		'automation_graph_rules.php' => array(
			'save' => true, 'actions' => false, 'item_movedown' => false, 'item_moveup' => false,
			'item_remove' => false, 'qedit' => false, 'remove' => false,
		),
		'automation_templates.php'   => array(
			'save' => true, 'ajax_dnd' => false, 'actions' => false, 'movedown' => false,
			'moveup' => false, 'remove' => false,
		),
		'automation_tree_rules.php'  => array(
			'save' => true, 'actions' => false, 'change_leaf' => false, 'item_movedown' => false,
			'item_moveup' => false, 'item_remove' => false, 'remove' => false,
		),
		'color.php'                  => array('save' => true, 'actions' => false, 'remove' => false),
		'data_queries.php'           => array(
			'save' => true, 'actions' => false, 'item_moveup_dssv' => false, 'item_movedown_dssv' => false,
			'item_remove_dssv' => false, 'item_moveup_gsv' => false, 'item_movedown_gsv' => false,
			'item_remove_gsv' => false, 'item_remove_confirm' => false, 'item_remove' => false, 'remove' => false,
		),
		'rrdcheck.php'               => array('purge' => false),
	);
}

/**
 * The guard statement at the top of a page's case, as written.
 *
 * @param string $page   The page file.
 * @param string $action The case label.
 *
 * @return string|null
 */
function guard_statement($page, $action) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $page);

	if (preg_match("/case '" . preg_quote($action, '/') . "':\s+(csrf_require_post\((?:true)?\);)/", $source, $matches) !== 1) {
		return null;
	}

	return $matches[1];
}

/**
 * Runs PHP code after the include/csrf.php method helpers in a child process,
 * because a refusal exits.
 *
 * @param string                $code    The code to run.
 * @param string                $method  The request method.
 * @param array<string, mixed>  $request The request variables.
 * @param array<string, mixed>  $post    The POST variables.
 * @param array<string, string> $server  Request headers as $_SERVER keys.
 *
 * @return string The response code, or 'pass' followed by the action when the code let it through.
 */
function run_checked($code, $method, array $request = array(), array $post = array(), array $server = array()) {
	$csrf    = file_get_contents(dirname(__DIR__, 4) . '/include/csrf.php');
	$helpers = '';

	foreach (array('csrf_require_post', 'csrf_request_is_cross_site', 'csrf_request_host_matches', 'csrf_strip_host_port') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $csrf, $matches) === 1) {
			$helpers .= $matches[0];
		}
	}

	$script = '<?php
		function isset_request_var($v) { return isset($_REQUEST[$v]); }
		function set_request_var($v, $x) { $_REQUEST[$v] = $x; }
		function cacti_log($m, $o = false, $e = "") { return true; }
		function raise_message($id, $text = "", $level = 0) { return true; }
		function header_location($url) { return true; }
		' . $helpers . '
		$_SERVER  = ' . var_export($server + array('REQUEST_METHOD' => $method, 'SERVER_NAME' => 'cacti.example'), true) . ';
		$_REQUEST = ' . var_export($request, true) . ';
		$_POST    = ' . var_export($post, true) . ';
		$passed   = false;
		register_shutdown_function(function () use (&$passed) {
			print $passed ? "pass" . (isset($_REQUEST["action"]) ? ":" . $_REQUEST["action"] : "") : (string) http_response_code();
		});
		(function () {
		' . $code . '
		})();
		$passed = true;';

	$file = tempnam(sys_get_temp_dir(), 'post');
	file_put_contents($file, $script);

	try {
		return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
	} finally {
		unlink($file);
	}
}

test('each page guard calls csrf_require_post, strict only for the save 1.2.31 already refused', function () {
	$wrong = array();

	foreach (page_guards() as $page => $actions) {
		foreach ($actions as $action => $strict) {
			$expected = $strict ? 'csrf_require_post(true);' : 'csrf_require_post();';

			if (guard_statement($page, $action) !== $expected) {
				$wrong[] = "$page:$action " . var_export(guard_statement($page, $action), true);
			}
		}
	}

	expect($wrong)->toBe(array());
});

test('a same-origin or header-less GET passes a page guard 1.2.31 did not have', function () {
	$wrong = array();

	$same = array(
		'no headers'                 => array(),
		'Sec-Fetch-Site same-origin' => array('HTTP_SEC_FETCH_SITE' => 'same-origin'),
		'Sec-Fetch-Site none'        => array('HTTP_SEC_FETCH_SITE' => 'none'),
		'matching Referer'           => array('HTTP_REFERER' => 'https://cacti.example/cacti/color.php'),
	);

	foreach (page_guards() as $page => $actions) {
		foreach ($actions as $action => $strict) {
			if ($strict) {
				continue;
			}

			foreach ($same as $label => $server) {
				$result = run_checked((string) guard_statement($page, $action), 'GET', array(), array(), $server);

				if ($result !== 'pass') {
					$wrong[] = "$page:$action $label: $result";
				}
			}
		}
	}

	expect($wrong)->toBe(array());
});

test('a cross-site GET gets 405 from every page guard', function () {
	$wrong = array();

	foreach (page_guards() as $page => $actions) {
		foreach (array_keys($actions) as $action) {
			foreach (array(array('HTTP_SEC_FETCH_SITE' => 'cross-site'), array('HTTP_REFERER' => 'https://attacker.example/')) as $server) {
				$result = run_checked((string) guard_statement($page, $action), 'GET', array(), array(), $server);

				if ($result !== '405') {
					$wrong[] = "$page:$action " . key($server) . ": $result";
				}
			}
		}
	}

	expect($wrong)->toBe(array());
});

test('a POST with a token passes every page guard from any site', function () {
	$wrong = array();

	foreach (page_guards() as $page => $actions) {
		foreach (array_keys($actions) as $action) {
			$result = run_checked((string) guard_statement($page, $action), 'POST', array(), array('__csrf_magic' => 'sid:x'), array('HTTP_SEC_FETCH_SITE' => 'cross-site'));

			if ($result !== 'pass') {
				$wrong[] = "$page:$action: $result";
			}
		}
	}

	expect($wrong)->toBe(array());
});

test('a strict guard refuses a GET from anywhere', function () {
	foreach (page_guards() as $page => $actions) {
		foreach ($actions as $action => $strict) {
			if (!$strict) {
				continue;
			}

			expect(run_checked((string) guard_statement($page, $action), 'GET'))->toBe('405', "$page:$action")
				->and(run_checked((string) guard_statement($page, $action), 'GET', array(), array(), array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('405', "$page:$action");
		}
	}
});

test('graph input removal answers any GET with 405 before the caller redirects', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/graph_templates_inputs.php');

	expect(preg_match('/function input_remove\(\) \{\n(.*?)\n\t\/\* =+ input validation/s', $source, $matches))->toBe(1);

	/* graph_templates_inputs.php redirects once input_remove() returns, so reaching
	   the marker after a refusal means the refusal did not end the request.
	   loadPageUsingPost() is the only caller, so unlike the other page guards
	   above, a same-origin GET here has nothing legitimate to serve either. */
	$check = $matches[1] . "\nset_request_var('action', 'removed');";

	expect(run_checked($check, 'GET'))->toBe('405')
		->and(run_checked($check, 'GET', array(), array(), array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('405')
		->and(run_checked($check, 'GET', array(), array(), array('HTTP_SEC_FETCH_SITE' => 'cross-site')))->toBe('405')
		->and(run_checked($check, 'DELETE'))->toBe('405')
		->and(run_checked($check, 'POST', array(), array('__csrf_magic' => 'sid:x'), array('HTTP_SEC_FETCH_SITE' => 'cross-site')))->toBe('pass:removed');
});

test('an RRD cleaner rescan refuses a GET only from another site', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/rrdcleaner.php');

	/* rescan=1 carries no action for the global guard, and rebuilds the RRDfile list */
	expect(preg_match("/\nif \(isset_request_var\('rescan'\)\) \{\n.*?\n}\n/s", $source, $matches))->toBe(1);

	$rescan = array('rescan' => '1');

	expect(run_checked($matches[0], 'GET', $rescan))->toBe('pass:restart')
		->and(run_checked($matches[0], 'GET', $rescan, array(), array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('pass:restart')
		->and(run_checked($matches[0], 'GET', $rescan, array(), array('HTTP_SEC_FETCH_SITE' => 'cross-site')))->toBe('405')
		->and(run_checked($matches[0], 'GET', $rescan, array(), array('HTTP_REFERER' => 'https://attacker.example/')))->toBe('405')
		->and(run_checked($matches[0], 'POST', $rescan, array('__csrf_magic' => 'sid:x'), array('HTTP_SEC_FETCH_SITE' => 'cross-site')))->toBe('pass:restart')
		->and(run_checked($matches[0], 'GET', array(), array(), array('HTTP_SEC_FETCH_SITE' => 'cross-site')))->toBe('pass');
});

test('only a GET gets the same-site relaxation; HEAD, PUT, DELETE and PATCH get 405', function () {
	$wrong = array();

	foreach (page_guards() as $page => $actions) {
		$statement = (string) guard_statement($page, array_keys($actions)[0]);

		foreach (array('HEAD', 'PUT', 'DELETE', 'PATCH') as $method) {
			foreach (array('no headers' => array(), 'same-origin' => array('HTTP_SEC_FETCH_SITE' => 'same-origin')) as $label => $server) {
				$result = run_checked($statement, $method, array(), array(), $server);

				if ($result !== '405') {
					$wrong[] = "$page $method $label: $result";
				}
			}
		}
	}

	expect($wrong)->toBe(array())
		->and(run_checked('csrf_require_post();', 'GET', array(), array(), array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('pass')
		->and(run_checked('csrf_require_post();', 'GET'))->toBe('pass');
});
