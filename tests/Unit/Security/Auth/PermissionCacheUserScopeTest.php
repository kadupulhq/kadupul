<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * poller_reports.php checks graphs and trees for several report owners in
 * one process, and $_SESSION persists across those checks. A cached
 * permission answer for one user must never be returned for another, a
 * session still holding the older unkeyed cache must recompute, and an owner
 * whose permissions an administrator reset must be rechecked. A single
 * signed-in user must get the answers an uncached check gives.
 *
 * The functions come from lib/auth.php and run in this namespace against
 * stubbed policy lookups: user 1 may see everything, user 7 is denied by
 * default with no exceptions.
 */

namespace PermissionCacheUserScopeTest;

if (!function_exists(__NAMESPACE__ . '\is_tree_allowed')) {
    require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

    $source = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');
    $code   = '';

    foreach (array('auth_check_perms', 'is_tree_allowed', 'get_simple_graph_perms', 'get_simple_graph_template_perms', 'auth_perm_cache_check_reset') as $name) {
        $code .= \test_php_function_source($source, $name) . "\n";
    }

    // test-only eval of source read from this repository, not external input
    eval('namespace ' . __NAMESPACE__ . '; ' . $code);
}

function read_config_option($name, $force = false)
{
    return $name == 'auth_method' ? 1 : '';
}

function db_fetch_cell_prepared($sql, $params = array(), $col_name = '', $log = true)
{
    if (preg_match('/SELECT (policy_[a-z_]+)\s+FROM user_auth\s/', $sql, $match)) {
        $GLOBALS['permission_cache']['queries'][] = $match[1];

        return $GLOBALS['permission_cache']['policies'][$params[0]][$match[1]];
    }

    if (preg_match('/SELECT reset_perms\s+FROM user_auth\s/', $sql)) {
        $GLOBALS['permission_cache']['reset_checks']++;

        return $GLOBALS['permission_cache']['reset_keys'][$params[0]] ?? false;
    }

    return 0;
}

function db_fetch_assoc_prepared($sql, $params = array(), $log = true)
{
    return array();
}

function kill_session_var($var_name)
{
    unset($_SESSION[$var_name]);
}

function cacti_sizeof($array)
{
    return ($array === false || !is_array($array)) ? 0 : count($array);
}

/* an administrator changes a user's policy from another process, as user_admin.php does */
function permission_cache_admin_change($user_id, $column, $value, $reset = true)
{
    $GLOBALS['permission_cache']['policies'][$user_id][$column] = $value;

    if ($reset) {
        $GLOBALS['permission_cache']['reset_keys'][$user_id] .= 'r';
    }
}

/**
 * Run permission checks as one process would and record, per call, which
 * policy columns were read and how often a reset key was read. A call
 * answered from the cache reads no policy column.
 *
 * @param list<array{0: string, 1: list<mixed>}> $calls
 * @param array<string, mixed>                   $session
 *
 * @return array{returns: list<mixed>, queries: list<list<string>>, reset_checks: list<int>, session: array<string, mixed>}
 */
function permission_cache_trace(array $calls, array $session = array()): array
{
    $saved    = $_SESSION ?? null;
    $_SESSION = $session;

    $GLOBALS['permission_cache'] = array(
        'policies' => array(
            1 => array('policy_graphs' => 1, 'policy_graph_templates' => 1, 'policy_trees' => 1),
            7 => array('policy_graphs' => 2, 'policy_graph_templates' => 2, 'policy_trees' => 2),
        ),
        /* user_auth.reset_perms, which reset_user_perms() and reset_group_perms() change */
        'reset_keys' => array(1 => '101', 7 => '107'),
    );

    $trace = array('returns' => array(), 'queries' => array(), 'reset_checks' => array());

    try {
        foreach ($calls as $call) {
            $GLOBALS['permission_cache']['queries']      = array();
            $GLOBALS['permission_cache']['reset_checks'] = 0;

            $trace['returns'][]      = call_user_func_array(__NAMESPACE__ . '\\' . $call[0], $call[1]);
            $trace['queries'][]      = $GLOBALS['permission_cache']['queries'];
            $trace['reset_checks'][] = $GLOBALS['permission_cache']['reset_checks'];
        }

        $trace['session'] = $_SESSION;
    } finally {
        $_SESSION = $saved;
    }

    return $trace;
}

/**
 * @param list<array{0: string, 1: list<mixed>}> $calls
 * @param array<string, mixed>                   $session
 *
 * @return list<mixed>
 */
function permission_cache_run(array $calls, array $session = array()): array
{
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

test('report owners given as database strings are kept apart', function () {
    expect(permission_cache_run(array(array('is_tree_allowed', array(5, '1')), array('is_tree_allowed', array(5, '7')))))->toBe(array(true, false));
});

test('the session user tree answer does not leak to an explicit user or back', function () {
    $calls = array(
        array('is_tree_allowed', array(5)),
        array('is_tree_allowed', array(5, 1)),
        array('is_tree_allowed', array(5)),
    );

    expect(permission_cache_run($calls, array('sess_user_id' => 7)))->toBe(array(false, true, false));
});

test('repeated checks for the signed-in user still use the cache', function () {
    $session = array(
        'sess_user_id'      => 7,
        'sess_tree_perms'   => array(7 => array(5 => true)),
        'sess_simple_perms' => array(7 => true),
        'sess_perms_reset_key' => array(7 => '107'),
    );

    $trace = permission_cache_trace(array(array('is_tree_allowed', array(5, 7)), array('get_simple_graph_perms', array(7))), $session);

    expect($trace['returns'])->toBe(array(true, true))
        ->and($trace['queries'])->toBe(array(array(), array()));
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
        ->and($trace['session']['sess_simple_perms'])->toBe(array(1 => true))
        ->and($trace['session']['sess_simple_template_perms'])->toBe(array(1 => true));
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

test('a permission reset for a report owner between two checks denies the later check', function () {
    foreach (array(
        array('is_tree_allowed', array(5, 1), 'policy_trees'),
        array('get_simple_graph_perms', array(1), 'policy_graphs'),
        array('get_simple_graph_template_perms', array(1), 'policy_graph_templates'),
    ) as $check) {
        $returns = permission_cache_run(array(
            array($check[0], $check[1]),
            array('permission_cache_admin_change', array(1, $check[2], 2)),
            array($check[0], $check[1]),
        ));

        expect($returns)->toBe(array(true, null, false));
    }
});

test('without a reset an owner answer is served from the cache after one reset key read', function () {
    $trace = permission_cache_trace(array(
        array('is_tree_allowed', array(5, 1)),
        array('permission_cache_admin_change', array(1, 'policy_trees', 2, false)),
        array('is_tree_allowed', array(5, 1)),
    ));

    expect($trace['returns'])->toBe(array(true, null, true))
        ->and($trace['queries'])->toBe(array(array('policy_trees'), array(), array()))
        ->and($trace['reset_checks'])->toBe(array(1, 0, 1));
});

test('a reset of one owner leaves another owner cached answers in place', function () {
    $trace = permission_cache_trace(array(
        array('is_tree_allowed', array(5, 1)),
        array('is_tree_allowed', array(5, 7)),
        array('permission_cache_admin_change', array(7, 'policy_trees', 1)),
        array('is_tree_allowed', array(5, 1)),
        array('is_tree_allowed', array(5, 7)),
    ));

    expect($trace['returns'])->toBe(array(true, false, null, true, true))
        ->and($trace['queries'][3])->toBe(array())
        ->and($trace['queries'][4])->toBe(array('policy_trees'));
});

test('the signed-in user checks read the reset key before cached answers', function () {
    /* Guest-enabled image routes may skip the realm gate before these helpers. */
    $trace = permission_cache_trace(array(
        array('is_tree_allowed', array(5)),
        array('get_simple_graph_perms', array(7)),
        array('get_simple_graph_template_perms', array('7')),
        array('is_tree_allowed', array(5, '7')),
        array('get_simple_graph_perms', array(7)),
    ), array('sess_user_id' => 7));

    expect($trace['returns'])->toBe(array(false, false, false, false, false))
        ->and($trace['reset_checks'])->toBe(array(1, 1, 1, 1, 1))
        ->and($trace['queries'][3])->toBe(array())
        ->and($trace['queries'][4])->toBe(array());
});

test('an administrator session rechecks another user after that user is reset', function () {
    $trace = permission_cache_trace(array(
        array('is_tree_allowed', array(5, 7)),
        array('permission_cache_admin_change', array(7, 'policy_trees', 1)),
        array('is_tree_allowed', array(5, 7)),
        array('is_tree_allowed', array(5)),
    ), array('sess_user_id' => 1));

    expect($trace['returns'])->toBe(array(false, null, true, true))
        ->and($trace['reset_checks'])->toBe(array(1, 0, 1, 1));
});
