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
 * On a remote collector with local storage, graph_image.php and
 * graph_json.php hand rendering to the main poller's remote_agent.php.
 * rrdtool_function_graph() checks graph permissions only for a user id
 * above zero, and remote_agent.php used user 0 when no effective_user
 * came with the request, a default kept from 1.2.x for compatibility.
 * include/auth.php guarantees a session user on both pages and no other
 * core code requests graph_json, so the callers now check
 * is_graph_allowed() before proxying and always forward the user, and
 * remote_agent.php refuses a graph request without one.
 *
 * get_graph_data() is extracted into this namespace with the request
 * helpers and the renderer stubbed.
 */

namespace RemoteAgentGraphUserTest;

if (!defined('FILTER_VALIDATE_MAX_DATE_AS_INT')) {
	define('FILTER_VALIDATE_MAX_DATE_AS_INT', 2088385563);
}

if (!function_exists(__NAMESPACE__ . '\get_graph_data')) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/remote_agent.php');

	preg_match('/^function get_graph_data\(.*?^}\n/ms', $source, $match);

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $match[0]);
}

function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array()) {
	return get_request_var($name);
}

function get_request_var($name) {
	return isset($GLOBALS['remote_agent_graph_request'][$name]) ? $GLOBALS['remote_agent_graph_request'][$name] : '';
}

function isset_request_var($name) {
	return isset($GLOBALS['remote_agent_graph_request'][$name]);
}

function isempty_request_var($name) {
	return empty($GLOBALS['remote_agent_graph_request'][$name]);
}

function cacti_validate_theme($theme) {
	return $theme;
}

function rrdtool_function_graph($local_graph_id, $rra_id, $graph_data_array, $rrdtool_pipe = false, &$xport_meta = array(), $user = 0) {
	$GLOBALS['remote_agent_graph_rendered'][] = $user;

	return 'image = rendered';
}

function remote_graph_request(array $request) {
	$GLOBALS['remote_agent_graph_request']  = $request;
	$GLOBALS['remote_agent_graph_rendered'] = array();

	ob_start();
	get_graph_data();

	return array(ob_get_clean(), $GLOBALS['remote_agent_graph_rendered']);
}

dataset('requests without a user', array(
	'no effective_user'   => array(array('local_graph_id' => 7)),
	'effective_user zero' => array(array('local_graph_id' => 7, 'effective_user' => 0)),
	'rejected by filter'  => array(array('local_graph_id' => 7, 'effective_user' => false)),
));

test('remote_agent refuses a graph request without a user', function ($request) {
	list($output, $rendered) = remote_graph_request($request);

	expect($output)->toBe('GRAPH ACCESS DENIED')
		->and($rendered)->toBe(array());
})->with('requests without a user');

test('remote_agent renders as the forwarded user', function () {
	list($output, $rendered) = remote_graph_request(array('local_graph_id' => 7, 'effective_user' => 12));

	expect($output)->toBe('image = rendered')
		->and($rendered)->toBe(array(12));
});

dataset('remote graph callers', array('graph_json.php', 'graph_image.php'));

test('console callers authorize and forward the user before proxying', function ($file) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

	$remote  = strpos($source, "remote_agent.php?action=graph_json");
	$branch  = strrpos(substr($source, 0, (int) $remote), "if (\$config['poller_id'] == 1 || read_config_option('storage_location')) {");
	$check   = strpos($source, "} elseif (!is_graph_allowed(get_request_var('local_graph_id'), \$_SESSION['sess_user_id'])) {", (int) $branch);
	$forward = strpos($source, "\$graph_data_array['effective_user'] = \$_SESSION['sess_user_id'];", (int) $check);

	expect($remote)->not->toBeFalse()
		->and($branch)->not->toBeFalse()
		->and($check)->not->toBeFalse()
		->and($forward)->not->toBeFalse()
		->and($check)->toBeLessThan($remote)
		->and($forward)->toBeLessThan($remote);
})->with('remote graph callers');
