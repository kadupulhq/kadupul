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
 * 1.2.31 refused only save, update_data and changepassword without a token.
 * The GET guard in include/global.php later refused every name on its list,
 * which broke plugin links, bookmarks and scripts that still send them by GET.
 * It now refuses those names only when the browser marks the request as coming
 * from another site.
 */

namespace CrossSiteGetGuardTest;

/**
 * The action names 1.2.31 refused from any request without a token.
 *
 * @return array<int, string>
 */
function legacy_actions() {
	return array('save', 'update_data', 'changepassword');
}

/**
 * The names on the include/global.php guard list.
 *
 * @return array<int, string>
 */
function listed_actions() {
	$global = file_get_contents(dirname(__DIR__, 4) . '/include/global.php');
	$start  = strpos($global, '$bad_actions = array(');
	$list   = substr($global, $start, strpos($global, ');', $start) - $start);

	preg_match_all("/'([a-z_]+)'/", $list, $names);

	return $names[1];
}

/**
 * Runs the include/global.php action guard in a child process, because it exits.
 *
 * @param string                $method  The request method.
 * @param array<string, mixed>  $request The request variables.
 * @param array<string, mixed>  $post    The POST variables.
 * @param array<string, string> $server  Request headers as $_SERVER keys.
 *
 * @return string The response code, or 'pass' when the guard let it through.
 */
function run_guard($method, array $request, array $post = array(), array $server = array()) {
	$root   = dirname(__DIR__, 4);
	$global = file_get_contents($root . '/include/global.php');
	$csrf   = file_get_contents($root . '/include/csrf.php');
	$start  = strpos($global, '/* check for save actions using GET */');
	$end    = strpos($global, "if (isset(\$_COOKIE['CactiTimeZone']))");

	if ($global === false || $csrf === false || $start === false || $end === false) {
		throw new \RuntimeException('Unable to extract the action guard from include/global.php.');
	}

	$helpers = '';

	foreach (array('csrf_request_is_cross_site', 'csrf_request_host_matches', 'csrf_strip_host_port') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $csrf, $matches) === 1) {
			$helpers .= $matches[0];
		}
	}

	$functions = file_get_contents($root . '/lib/functions.php');
	foreach (array('sanitize_uri', 'is_urlencoded') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $functions, $matches) !== 1) {
			throw new \RuntimeException('Missing URI helper');
		}
		$helpers .= $matches[0];
	}
	if (preg_match('/\tif \(isset\(\$_SERVER\[\'HTTP_REFERER\'\]\)\) \{.*?\n\t}/s', $global, $referer) !== 1) {
		throw new \RuntimeException('Missing Referer sanitization');
	}

	$script = '<?php
		function isset_request_var($v) { return isset($_REQUEST[$v]); }
		function get_nfilter_request_var($n, $d = "") { return isset($_REQUEST[$n]) ? $_REQUEST[$n] : $d; }
		function cacti_log($m, $o = false, $e = "") { return true; }
		function get_client_addr() { return "192.0.2.10"; }
		' . $helpers . '
		$_SERVER  = ' . var_export($server + array('REQUEST_METHOD' => $method, 'SERVER_NAME' => 'cacti.example'), true) . ';
		$_REQUEST = ' . var_export($request, true) . ';
		$_POST    = ' . var_export($post, true) . ';
		$passed   = false;
		register_shutdown_function(function () use (&$passed) {
			print $passed ? "pass" : (string) http_response_code();
		});
		' . $referer[0] . substr($global, $start, $end - $start) . '
		$passed = true;';

	$file = tempnam(sys_get_temp_dir(), 'guard');
	file_put_contents($file, $script);

	try {
		return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
	} finally {
		unlink($file);
	}
}

/**
 * Headers a browser sends when another site starts the request.
 *
 * @return array<string, array<string, string>>
 */
function cross_site_headers() {
	return array(
		'Sec-Fetch-Site cross-site'  => array('HTTP_SEC_FETCH_SITE' => 'cross-site'),
		'Sec-Fetch-Site same-site'   => array('HTTP_SEC_FETCH_SITE' => 'same-site'),
		'mismatched Origin'          => array('HTTP_ORIGIN' => 'https://attacker.example'),
		'Origin null'                => array('HTTP_ORIGIN' => 'null'),
		'mismatched Referer'         => array('HTTP_REFERER' => 'https://attacker.example/cacti/host.php'),
		'Referer with a user name'   => array('HTTP_REFERER' => 'https://cacti.example@attacker.example/'),
		'Referer on a lookalike host' => array('HTTP_REFERER' => 'https://cacti.example.attacker.example/'),
	);
}

/**
 * Headers for a request from Cacti's own pages, a bookmark, or no browser.
 *
 * @return array<string, array<string, string>>
 */
function same_site_headers() {
	return array(
		'no headers'                 => array(),
		'Sec-Fetch-Site same-origin' => array('HTTP_SEC_FETCH_SITE' => 'same-origin'),
		'Sec-Fetch-Site none'        => array('HTTP_SEC_FETCH_SITE' => 'none'),
		'matching Referer'           => array('HTTP_REFERER' => 'https://cacti.example/cacti/host.php?action=edit'),
		'matching Origin'            => array('HTTP_ORIGIN' => 'https://cacti.example'),
	);
}

/**
 * State changing actions 1.2.31 accepted by GET, still called that way by
 * their own pages and by plugins.
 *
 * @return array<int, string>
 */
function legacy_get_actions() {
	return array(
		'logout_everywhere', 'clear_user_settings', 'reset_default',
		'ajax_dnd', 'lock', 'unlock', 'sortasc', 'sortdesc', 'set_branch_sort', 'set_host_sort',
		'ajax_reports', 'update_timespan',
		'run_debug', 'run_repair', 'runall', 'ds_disable', 'ds_enable',
		'query_reload', 'ajax_save', 'ajax_save_filter',
		'reindex', 'gt_add', 'query_add', 'query_change', 'query_verbose',
		'ping_host', 'enable_debug', 'disable_debug', 'repopulate',
		'item_add_gt', 'item_remove_gt', 'item_add_dq', 'item_remove_dq',
		'ping', 'restart', 'remall', 'arcall', 'send_test', 'perm_remove',
		'clear_poller_cache', 'rebuild_resource_cache', 'clear_logfile', 'purge_logfile', 'clear_user_log',
	);
}

test('the state changing GET actions 1.2.31 accepted are refused only from another site', function () {
	$listed  = listed_actions();
	$missing = array_values(array_diff(legacy_get_actions(), $listed));
	$wrong   = array();

	expect($missing)->toBe(array());

	foreach (legacy_get_actions() as $action) {
		$headerless = run_guard('GET', array('action' => $action));
		$same       = run_guard('GET', array('action' => $action), array(), array('HTTP_SEC_FETCH_SITE' => 'same-origin'));
		$cross      = run_guard('GET', array('action' => $action), array(), array('HTTP_SEC_FETCH_SITE' => 'cross-site'));

		if ($headerless !== 'pass' || $same !== 'pass' || $cross !== '405') {
			$wrong[] = "$action: $headerless/$same/$cross";
		}
	}

	expect($wrong)->toBe(array());
});

test('a GET without site headers behaves as in 1.2.31 for every listed action', function () {
	$actions = listed_actions();

	expect($actions)->toContain('item_remove');

	$differ = array();

	foreach ($actions as $action) {
		$expected = in_array($action, legacy_actions(), true) ? '405' : 'pass';
		$result   = run_guard('GET', array('action' => $action));

		if ($result !== $expected) {
			$differ[] = $action . ': ' . $result;
		}
	}

	expect($differ)->toBe(array())
		->and(run_guard('GET', array('action' => 'actions', 'selected_items' => 'x')))->toBe('pass');
});

test('a same-origin GET of a listed action passes', function () {
	foreach (same_site_headers() as $label => $server) {
		expect(run_guard('GET', array('action' => 'item_remove'), array(), $server))->toBe('pass', $label)
			->and(run_guard('GET', array('action' => 'delete_node'), array(), $server))->toBe('pass', $label)
			->and(run_guard('GET', array('action' => 'actions', 'selected_items' => 'x'), array(), $server))->toBe('pass', $label);
	}
});

test('a cross-site GET of a listed action is refused', function () {
	foreach (cross_site_headers() as $label => $server) {
		expect(run_guard('GET', array('action' => 'item_remove'), array(), $server))->toBe('405', $label)
			->and(run_guard('GET', array('action' => 'delete_node'), array(), $server))->toBe('405', $label)
			->and(run_guard('GET', array('action' => 'actions', 'selected_items' => 'x'), array(), $server))->toBe('405', $label)
			->and(run_guard('GET', array('action' => 'save'), array(), $server))->toBe('405', $label);
	}
});

test('a POST with a token passes from any site', function () {
	$token = array('__csrf_magic' => 'sid:x');

	foreach (cross_site_headers() + same_site_headers() as $label => $server) {
		expect(run_guard('POST', array('action' => 'item_remove'), $token, $server))->toBe('pass', $label)
			->and(run_guard('POST', array('action' => 'save'), $token, $server))->toBe('pass', $label)
			->and(run_guard('POST', array('action' => 'actions', 'selected_items' => 'x'), $token, $server))->toBe('pass', $label);
	}
});

test('Sec-Fetch-Site decides before Origin and Referer', function () {
	/* a page cannot set Sec-Fetch-Site, while a proxy that rewrites Host can make
	   a same-origin Referer look foreign */
	expect(run_guard('GET', array('action' => 'item_remove'), array(), array('HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_REFERER' => 'https://attacker.example/')))->toBe('pass')
		->and(run_guard('GET', array('action' => 'item_remove'), array(), array('HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_REFERER' => 'https://cacti.example/')))->toBe('405');
});

test('the Origin and Referer fallback compares the host name only', function () {
	/* TLS ending at a proxy changes the scheme and often the port, and a browser
	   that would tell another port apart sends Sec-Fetch-Site instead */
	expect(run_guard('GET', array('action' => 'item_remove'), array(), array('HTTP_REFERER' => 'http://cacti.example:8080/cacti/')))->toBe('pass')
		->and(run_guard('GET', array('action' => 'item_remove'), array(), array('SERVER_NAME' => 'cacti.example:8443', 'HTTP_REFERER' => 'https://CACTI.example/')))->toBe('pass')
		->and(run_guard('GET', array('action' => 'item_remove'), array(), array('SERVER_NAME' => '[::1]', 'HTTP_ORIGIN' => 'http://[::1]:8080')))->toBe('pass')
		->and(run_guard('GET', array('action' => 'item_remove'), array(), array('SERVER_NAME' => '[::1]', 'HTTP_ORIGIN' => 'http://[::2]')))->toBe('405');
});

test('the fallback normalizes IPv4, name and IPv6 SERVER_NAME the way parse_url reports the Origin host', function () {
	/* SERVER_NAME may carry a :port, a bracketed IPv6 host with or without a
	   :port, or a bare IPv6 host with no brackets and no port, which is what
	   Apache and nginx report when the Host header names an IPv6 address
	   with no port; stripping a trailing :digits there would eat the
	   address's last hextet instead of a port */
	expect(run_guard('GET', array('action' => 'item_remove'), array(), array('SERVER_NAME' => '192.0.2.10', 'HTTP_ORIGIN' => 'http://192.0.2.10/')))->toBe('pass')
		->and(run_guard('GET', array('action' => 'item_remove'), array(), array('SERVER_NAME' => 'cacti.example', 'HTTP_ORIGIN' => 'http://cacti.example/')))->toBe('pass')
		->and(run_guard('GET', array('action' => 'item_remove'), array(), array('SERVER_NAME' => 'cacti.example:8443', 'HTTP_ORIGIN' => 'https://cacti.example/')))->toBe('pass')
		->and(run_guard('GET', array('action' => 'item_remove'), array(), array('SERVER_NAME' => '[::1]:8443', 'HTTP_ORIGIN' => 'http://[::1]/')))->toBe('pass')
		->and(run_guard('GET', array('action' => 'item_remove'), array(), array('SERVER_NAME' => '::1', 'HTTP_ORIGIN' => 'http://[::1]/')))->toBe('pass')
		->and(run_guard('GET', array('action' => 'item_remove'), array(), array('SERVER_NAME' => '2001:db8::1', 'HTTP_ORIGIN' => 'http://[2001:db8::1]/')))->toBe('pass')
		->and(run_guard('GET', array('action' => 'item_remove'), array(), array('SERVER_NAME' => '::1', 'HTTP_ORIGIN' => 'http://[::2]/')))->toBe('405');
});

test('the fallback compares the server name, not the Host header the client sent', function () {
	/* the Host header is client input, so a Referer naming the same foreign host
	   must not count as same-site */
	expect(run_guard('GET', array('action' => 'item_remove'), array(), array('HTTP_HOST' => 'attacker.example', 'HTTP_REFERER' => 'https://attacker.example/')))->toBe('405')
		->and(run_guard('GET', array('action' => 'item_remove'), array(), array('HTTP_HOST' => 'attacker.example', 'HTTP_REFERER' => 'https://cacti.example/cacti/')))->toBe('pass');

	$csrf = file_get_contents(dirname(__DIR__, 4) . '/include/csrf.php');

	expect($csrf)->not->toContain("\$_SERVER['HTTP_HOST']");
});

test('plugins.php sends every mode except check through csrf_require_post', function () {
	$plugins = file_get_contents(dirname(__DIR__, 4) . '/plugins.php');

	expect($plugins)->toMatch("/if \\(\\\$mode !== 'check'\\) \\{\\s+csrf_require_post\\(\\);/");
});

/**
 * The top-level case labels of every action switch in Cacti's pages, with the
 * text of each case.
 *
 * @return array<string, string> page:label => case text
 */
function dispatched_actions() {
	$found = array();

	foreach (glob(dirname(__DIR__, 4) . '/*.php') as $path) {
		$src  = file_get_contents($path);
		$page = basename($path);

		if (!preg_match_all("/switch\s*\(\s*get_(?:n?filter_)?request_var\('action'\)\s*\)\s*\{/", $src, $switches, PREG_OFFSET_CAPTURE)) {
			continue;
		}

		foreach ($switches[0] as $switch) {
			$start = $switch[1] + strlen($switch[0]);
			$depth = 1;

			for ($end = $start; $end < strlen($src) && $depth > 0; $end++) {
				if ($src[$end] === '{') {
					$depth++;
				} elseif ($src[$end] === '}') {
					$depth--;
				}
			}

			$body = substr($src, $start, $end - $start - 1);

			preg_match_all("/\n\s*case\s+'([A-Za-z0-9_]+)'\s*[:;]/", $body, $cases, PREG_OFFSET_CAPTURE);

			$top = array();

			foreach ($cases[1] as $i => $case) {
				$before = substr($body, 0, $cases[0][$i][1]);

				if (substr_count($before, '{') === substr_count($before, '}')) {
					$top[] = array($case[0], $cases[0][$i][1]);
				}
			}

			foreach ($top as $i => $case) {
				$next = isset($top[$i + 1]) ? $top[$i + 1][1] : strlen($body);
				$key  = $page . ':' . $case[0];

				$found[$key] = (isset($found[$key]) ? $found[$key] : '') . substr($body, $case[1], $next - $case[1]);
			}
		}
	}

	ksort($found);

	return $found;
}

/**
 * Actions that render a page, a dialog, an export or an AJAX answer and change
 * nothing, so GET from anywhere stays allowed.
 *
 * @return array<string, array<int, string>>
 */
function read_only_actions() {
	return array(
		'aggregate_graphs.php'      => array('edit'),
		'aggregate_templates.php'   => array('edit'),
		'automation_devices.php'    => array('export'),
		'automation_graph_rules.php' => array('item_edit', 'edit'),
		'automation_networks.php'   => array('edit'),
		'automation_snmp.php'       => array('item_remove_confirm', 'item_edit', 'edit'),
		'automation_templates.php'  => array('edit'),
		'automation_tree_rules.php' => array('item_edit', 'edit'),
		'cdef.php'                  => array('item_remove_confirm', 'item_edit', 'edit'),
		'color.php'                 => array('edit', 'export', 'import'),
		'color_templates.php'       => array('template_edit'),
		'color_templates_items.php' => array('item_remove_confirm', 'item_edit', 'item'),
		'data_debug.php'            => array('view', 'ajax_hosts', 'ajax_hosts_noany'),
		'data_input.php'            => array('field_remove_confirm', 'field_edit', 'edit'),
		'data_queries.php'          => array('item_edit', 'edit'),
		'data_source_profiles.php'  => array('item_remove_confirm', 'ajax_span', 'ajax_size', 'item_edit', 'edit'),
		'data_sources.php'          => array('data_edit', 'ds_edit', 'ajax_hosts', 'ajax_hosts_noany'),
		'data_templates.php'        => array('template_edit'),
		'gprint_presets.php'        => array('edit'),
		'graph.php'                 => array('view', 'zoom', 'properties'),
		'graph_realtime.php'        => array('init', 'timespan', 'interval', 'countdown', 'view'),
		'graph_templates.php'       => array('input_edit', 'template_edit'),
		'graph_templates_inputs.php' => array('input_edit'),
		'graph_templates_items.php' => array('ajax_data_sources', 'item_edit', 'item'),
		'graph_view.php'            => array('ajax_hosts', 'ajax_search', 'tree', 'get_node', 'tree_content', 'preview', 'list'),
		'graphs.php'                => array('item', 'ajax_graph_items', 'ajax_hosts', 'ajax_hosts_noany', 'graph_edit'),
		'graphs_items.php'          => array('item_edit', 'ajax_hosts', 'ajax_hosts_noany', 'ajax_graph_items'),
		'graphs_new.php'            => array('ajax_hosts', 'ajax_hosts_noany'),
		'host.php'                  => array('export', 'edit', 'ajax_locations'),
		'host_templates.php'        => array('item_remove_gt_confirm', 'item_remove_dq_confirm', 'edit'),
		'links.php'                 => array('edit'),
		'package_import.php'        => array('details', 'diff'),
		'pollers.php'               => array('ajax_tz', 'edit'),
		/* Remote Data Collector calls, authorized by the poller address rather than a browser session */
		'remote_agent.php'          => array('polldata', 'runquery', 'snmpget', 'snmpwalk', 'graph_json', 'discover'),
		'reports_admin.php'         => array('setvar', 'ajax_get_branches', 'ajax_hosts', 'ajax_graphs', 'ajax_graph_template', 'item_edit', 'edit'),
		'reports_user.php'          => array('setvar', 'ajax_get_branches', 'ajax_hosts', 'ajax_graphs', 'ajax_graph_template', 'item_edit', 'edit'),
		'sites.php'                 => array('ajax_tz', 'edit'),
		'tree.php'                  => array('edit', 'sites', 'hosts', 'graphs', 'get_node', 'get_host_sort', 'get_branch_sort'),
		'user_admin.php'            => array('user_edit', 'checkpass'),
		'user_domains.php'          => array('edit'),
		'user_group_admin.php'      => array('edit'),
		'utilities.php'             => array(
			'view_snmp_cache', 'view_poller_cache', 'view_logfile', 'view_user_log', 'view_tech',
			'view_boost_status', 'view_snmpagent_cache', 'view_snmpagent_events', 'ajax_hosts', 'ajax_hosts_noany',
		),
		'vdef.php'                  => array('item_remove_confirm', 'item_edit', 'edit'),
	);
}

test('every dispatched action is guarded or listed as read-only', function () {
	$listed    = listed_actions();
	$read_only = array();

	foreach (read_only_actions() as $page => $labels) {
		foreach ($labels as $label) {
			$read_only[$page . ':' . $label] = true;
		}
	}

	$wrong = array();
	$stale = $read_only;

	foreach (dispatched_actions() as $key => $text) {
		$label = substr($key, strpos($key, ':') + 1);

		/* 'actions' changes data only once selected_items arrives, which the guard refuses from another site */
		$guarded = in_array($label, $listed, true) || $label === 'actions'
			|| strpos($text, 'csrf_require_post(') !== false || strpos($text, 'REQUEST_METHOD') !== false;

		if (isset($read_only[$key])) {
			unset($stale[$key]);

			if ($guarded) {
				$wrong[] = $key . ' is guarded but also listed as read-only';
			}
		} elseif (!$guarded) {
			$wrong[] = $key . ' is neither guarded nor listed as read-only';
		}
	}

	expect($wrong)->toBe(array())
		->and(array_keys($stale))->toBe(array());
});

test('device template and remaining removal and send actions are refused only from another site', function () {
	$actions = array(
		'item_add_gt', 'item_add_dq', 'item_remove_gt', 'item_remove_dq',
		'field_remove', 'ds_remove', 'template_remove', 'input_remove', 'send',
		'purge_data_source_statistics', 'rebuild_snmpagent_cache',
	);

	expect(array_values(array_diff($actions, listed_actions())))->toBe(array());

	foreach ($actions as $action) {
		expect(run_guard('GET', array('action' => $action)))->toBe('pass', $action)
			->and(run_guard('GET', array('action' => $action), array(), array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('pass', $action)
			->and(run_guard('GET', array('action' => $action), array(), array('HTTP_SEC_FETCH_SITE' => 'cross-site')))->toBe('405', $action);
	}
});

test('only GET gets the same-site relaxation on a guarded action; HEAD, PUT, DELETE and PATCH get 405', function () {
	/* data_input.php?action=field_remove and reports_*.php?action=send have no
	   local csrf_require_post(), so a same-origin non-GET without a token must
	   not fall through this guard to reach them */
	foreach (array('item_remove', 'actions', 'field_remove', 'send') as $action) {
		$request = $action === 'actions' ? array('action' => $action, 'selected_items' => 'x') : array('action' => $action);

		foreach (array('HEAD', 'PUT', 'DELETE', 'PATCH') as $method) {
			expect(run_guard($method, $request))->toBe('405', "$action $method")
				->and(run_guard($method, $request, array(), array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('405', "$action $method same-origin");
		}
	}
});


test('a token field on a method csrf-magic never validates does not excuse the request', function () {
	// csrf_check() validates the token only on POST, so a field of that name on
	// any other method proved nothing and skipped the guard entirely.
	foreach (array('DELETE', 'PUT', 'PATCH', 'HEAD') as $method) {
		expect(run_guard($method, array('action' => 'remove'), array('__csrf_magic' => 'anything')))->toBe('405', $method);
		expect(run_guard($method, array('action' => 'save'), array('__csrf_magic' => 'anything')))->toBe('405', $method);
		expect(run_guard($method, array('action' => 'actions', 'selected_items' => 'a:1:{i:0;i:3;}'), array('__csrf_magic' => 'anything')))->toBe('405', $method);
	}
});

test('a POST that carries its token still passes', function () {
	expect(run_guard('POST', array('action' => 'remove'), array('__csrf_magic' => 'token')))->toBe('pass');
	expect(run_guard('POST', array('action' => 'save'), array('__csrf_magic' => 'token')))->toBe('pass');
	expect(run_guard('POST', array('action' => 'actions', 'selected_items' => 'a:1:{i:0;i:3;}'), array('__csrf_magic' => 'token')))->toBe('pass');
});

test('IPv6 Referer survives global bootstrap before same-site validation', function () {
    expect(run_guard('GET', array('action' => 'item_remove'), array(), array('SERVER_NAME' => '::1', 'HTTP_REFERER' => 'http://[::1]/cacti/')))->toBe('pass')
        ->and(run_guard('GET', array('action' => 'item_remove'), array(), array('SERVER_NAME' => '::1', 'HTTP_REFERER' => 'http://[::2]/cacti/')))->toBe('405');
});
