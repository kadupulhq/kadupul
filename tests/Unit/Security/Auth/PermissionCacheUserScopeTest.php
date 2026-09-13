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
 * The report poller checks graphs and trees for several report owners in one
 * process. A cached permission answer for one user must never be returned for
 * another, and a session still holding the older unkeyed cache must recompute.
 * A single signed-in user must get the answers an uncached check gives.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

/**
 * Run permission checks in a fresh process and record, per call, which policy
 * columns were read from user_auth. A call answered from the cache reads none.
 *
 * @param array<int, array{0: string, 1: array<int, mixed>}> $calls
 * @param array<string, mixed>                                $session
 *
 * @return array{returns: array<int, mixed>, queries: array<int, array<int, string>>, session: array<string, mixed>}
 */
function permission_cache_trace(array $calls, array $session = array()) : array {
	$src  = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');
	$body = '';

	foreach (array('auth_check_perms', 'is_tree_allowed', 'get_simple_graph_perms', 'get_simple_graph_template_perms') as $name) {
		$body .= cacti_test_function_source($src, $name) . "\n\n";
	}

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

/* user 1 may see everything; user 7 is denied by default with no exceptions */
$GLOBALS['policies'] = array(
	1 => array('policy_graphs' => 1, 'policy_graph_templates' => 1, 'policy_trees' => 1),
	7 => array('policy_graphs' => 2, 'policy_graph_templates' => 2, 'policy_trees' => 2),
);

$GLOBALS['queries'] = array();

function read_config_option($name, $force = false) {
	return $name == 'auth_method' ? 1 : '';
}

function db_fetch_cell_prepared($sql, $params = array(), $col_name = '', $log = true) {
	if (preg_match('/SELECT (policy_[a-z_]+)\s+FROM user_auth\s/', $sql, $match)) {
		$GLOBALS['queries'][] = $match[1];

		return $GLOBALS['policies'][$params[0]][$match[1]];
	}

	return 0;
}

function db_fetch_assoc_prepared($sql, $params = array(), $log = true) {
	return array();
}

function kill_session_var($var_name) {
	unset($_SESSION[$var_name]);
}

function cacti_sizeof($array) {
	return ($array === false || !is_array($array)) ? 0 : sizeof($array);
}

PHP;

	$source .= $body;
	$source .= <<<'PHP'
$_SESSION = $scenario['session'];
$returns      = array();
$call_queries = array();

/* this runs at file scope, where $queries would be $GLOBALS['queries'] itself */
foreach ($scenario['calls'] as $call) {
	$GLOBALS['queries'] = array();
	$returns[]      = call_user_func_array($call[0], $call[1]);
	$call_queries[] = $GLOBALS['queries'];
}

print json_encode(array('returns' => $returns, 'queries' => $call_queries, 'session' => $_SESSION));

PHP;

	return cacti_test_run_php_source($source, array('calls' => $calls, 'session' => $session));
}

/**
 * @param array<int, array{0: string, 1: array<int, mixed>}> $calls
 * @param array<string, mixed>                                $session
 *
 * @return array<int, mixed>
 */
function permission_cache_run(array $calls, array $session = array()) : array {
	return permission_cache_trace($calls, $session)['returns'];
}

test('simple graph permissions are not shared between users in one process', function () {
	expect(permission_cache_run(array(array('get_simple_graph_perms', array(1)), array('get_simple_graph_perms', array(7)))))->toBe(array(true, false))
		->and(permission_cache_run(array(array('get_simple_graph_perms', array(7)), array('get_simple_graph_perms', array(1)))))->toBe(array(false, true));
});

test('simple graph template permissions are not shared between users in one process', function () {
	expect(permission_cache_run(array(array('get_simple_graph_template_perms', array(1)), array('get_simple_graph_template_perms', array(7)))))->toBe(array(true, false))
		->and(permission_cache_run(array(array('get_simple_graph_template_perms', array(7)), array('get_simple_graph_template_perms', array(1)))))->toBe(array(false, true));
});

test('tree permissions are not shared between users in one process', function () {
	expect(permission_cache_run(array(array('is_tree_allowed', array(5, 1)), array('is_tree_allowed', array(5, 7)))))->toBe(array(true, false))
		->and(permission_cache_run(array(array('is_tree_allowed', array(5, 7)), array('is_tree_allowed', array(5, 1)))))->toBe(array(false, true));
});

test('the session user tree answer does not leak to an explicit user or back', function () {
	$calls = array(
		array('is_tree_allowed', array(5)),
		array('is_tree_allowed', array(5, 1)),
		array('is_tree_allowed', array(5)),
	);

	expect(permission_cache_run($calls, array('sess_user_id' => 7)))->toBe(array(false, true, false));
});

test('repeated checks for the same user still use the cache', function () {
	$calls = array(
		array('is_tree_allowed', array(5, 7)),
		array('get_simple_graph_perms', array(7)),
	);

	$session = array(
		'sess_tree_perms'   => array(7 => array(5 => true)),
		'sess_simple_perms' => array(7 => true),
	);

	expect(permission_cache_run($calls, $session))->toBe(array(true, true));
});

test('an unkeyed permission cache left in an older session is recomputed', function () {
	$calls = array(
		array('get_simple_graph_perms', array(7)),
		array('get_simple_graph_template_perms', array(7)),
		array('is_tree_allowed', array(5, 7)),
	);

	$session = array(
		'sess_simple_perms'          => true,
		'sess_simple_template_perms' => true,
		'sess_tree_perms'            => array(7 => true, 5 => true),
	);

	expect(permission_cache_run($calls, $session))->toBe(array(false, false, false));
});

test('a single signed-in user gets the same answers with the cache as without it', function () {
	foreach (array(1, 7) as $user_id) {
		$calls = array(
			array('is_tree_allowed', array(5)),
			array('get_simple_graph_perms', array($user_id)),
			array('get_simple_graph_template_perms', array($user_id)),
			array('is_tree_allowed', array(5, $user_id)),
			array('is_tree_allowed', array(5)),
			array('get_simple_graph_perms', array($user_id)),
			array('get_simple_graph_template_perms', array($user_id)),
		);

		$session = array('sess_user_id' => $user_id);
		$fresh   = array();

		foreach ($calls as $call) {
			$fresh[] = permission_cache_run(array($call), $session)[0];
		}

		expect(permission_cache_run($calls, $session))->toBe($fresh)
			->and($fresh)->toBe(array_fill(0, 7, $user_id === 1));
	}
});

test('a signed-out tree check is still denied and user -1 still passes', function () {
	expect(permission_cache_run(array(array('is_tree_allowed', array(5)), array('is_tree_allowed', array(5)))))->toBe(array(false, false))
		->and(permission_cache_run(array(array('is_tree_allowed', array(5, -1))), array('sess_user_id' => 7)))->toBe(array(true));
});

test('a denied answer is served from the cache on the next check', function () {
	/* isset() is true for a cached false, so a denial is not recomputed */
	foreach (array(
		array('is_tree_allowed', array(5, 7), 'policy_trees'),
		array('get_simple_graph_perms', array(7), 'policy_graphs'),
		array('get_simple_graph_template_perms', array(7), 'policy_graph_templates'),
	) as $check) {
		$trace = permission_cache_trace(array(array($check[0], $check[1]), array($check[0], $check[1])));

		expect($trace['returns'])->toBe(array(false, false))
			->and($trace['queries'])->toBe(array(array($check[2]), array()));
	}
});

test('a false unkeyed tree entry whose key equals the user id discards the old cache', function () {
	/* in the old layout the keys are tree ids, so tree 1 denied collides with user 1 */
	$trace = permission_cache_trace(array(array('is_tree_allowed', array(9, 1))), array('sess_tree_perms' => array(1 => false, 5 => true)));

	expect($trace['returns'])->toBe(array(true))
		->and($trace['queries'])->toBe(array(array('policy_trees')))
		->and($trace['session']['sess_tree_perms'])->toBe(array(1 => array(9 => true)));
});

test('a false unkeyed simple permission cache is discarded rather than served', function () {
	$trace = permission_cache_trace(array(
		array('get_simple_graph_perms', array(1)),
		array('get_simple_graph_template_perms', array(1)),
	), array('sess_simple_perms' => false, 'sess_simple_template_perms' => false));

	expect($trace['returns'])->toBe(array(true, true))
		->and($trace['queries'])->toBe(array(array('policy_graphs'), array('policy_graph_templates')))
		->and($trace['session']['sess_simple_perms'])->toBe(array(1 => true))
		->and($trace['session']['sess_simple_template_perms'])->toBe(array(1 => true));
});
