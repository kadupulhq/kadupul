<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
function cacti_authorize_is_admin($user_id) { return $GLOBALS['authorize_admin']; }
function cacti_authorize_has_realm($user_id, $realm) { return false; }
function db_fetch_cell_prepared($sql, $params) {
    $GLOBALS['authorize_queries'][] = array($sql, $params);
    return $GLOBALS['authorize_owner'];
}
eval(test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php'), 'cacti_authorize_resource'));

/** Configure dependency responses, then invoke the actual production decision. */
function decide_authorize($user_id, $resource_id, $resource_type, $owner, $admin) {
    $GLOBALS['authorize_owner'] = $owner;
    $GLOBALS['authorize_admin'] = $admin;
    $GLOBALS['authorize_queries'] = array();
    return cacti_authorize_resource($user_id, $resource_id, $resource_type);
}

// cacti_authorize_resource source contract
$src = file_get_contents(__DIR__ . '/../../../../lib/auth.php');

it('cacti_authorize_resource source contract — casts user_id and resource_id to int before any decision', function () use ($src) {
	expect($src)->toContain('$user_id     = (int) $user_id');
	expect($src)->toContain('$resource_id = (int) $resource_id');
});

it('cacti_authorize_resource source contract — rejects non-positive ids before any further work', function () use ($src) {
	expect($src)->toMatch('/if\s*\(\$user_id\s*<=\s*0\s*\|\|\s*\$resource_id\s*<=\s*0\)\s*\{[^}]*return\s+false/s');
});

it('cacti_authorize_resource source contract — falls through to false for unknown resource types', function () use ($src) {
	expect($src)->toContain('default:')
		->and($src)->toMatch('/default:\s*\/\/[^\n]*fail closed[^\n]*\n\s*return\s+false/');
});

it('cacti_authorize_resource source contract — uses prepared queries for ownership lookups', function () use ($src) {
	expect($src)->toContain('db_fetch_cell_prepared');
	expect($src)->not->toMatch('/db_fetch_cell\s*\([^)]*\$user_id/');  // no raw concat
});

it('cacti_authorize_resource source contract — caches admin status per-request', function () use ($src) {
	expect($src)->toContain('static $admin_cache');
});

// authorization decision logic
it('authorization decision logic — rejects zero user id', function () {
	expect(decide_authorize(0, 10, 'reports', 5, false))->toBeFalse();
});

it('authorization decision logic — rejects negative user id', function () {
	expect(decide_authorize(-1, 10, 'reports', 5, false))->toBeFalse();
});

it('authorization decision logic — rejects zero resource id', function () {
	expect(decide_authorize(5, 0, 'reports', 5, false))->toBeFalse();
});

it('authorization decision logic — accepts owner of reports', function () {
	expect(decide_authorize(5, 10, 'reports', 5, false))->toBeTrue();
});

it('authorization decision logic — rejects non-owner of reports', function () {
	expect(decide_authorize(7, 10, 'reports', 5, false))->toBeFalse();
});

it('authorization decision logic — accepts owner of graph_tree', function () {
	expect(decide_authorize(5, 10, 'graph_tree', 5, false))->toBeTrue();
});

it('authorization decision logic — rejects non-owner of graph_tree', function () {
	expect(decide_authorize(99, 10, 'graph_tree', 5, false))->toBeFalse();
});

it('authorization decision logic — allows admin to bypass ownership', function () {
	// User 99 is admin; owner is 5; admin wins.
	expect(decide_authorize(99, 10, 'reports', 5, true))->toBeTrue();
});

it('authorization decision logic — allows user to modify own settings_user row', function () {
	expect(decide_authorize(5, 5, 'settings_user', null, false))->toBeTrue();
});

it('authorization decision logic — rejects user modifying another user settings_user row', function () {
	expect(decide_authorize(5, 7, 'settings_user', null, false))->toBeFalse();
});

it('authorization decision logic — fails closed for unknown resource types', function () {
	expect(decide_authorize(5, 10, 'totally_unknown', 5, false))->toBeFalse();
});

it('authorization decision logic — rejects null owner (missing resource row) for non-admin', function () {
	expect(decide_authorize(5, 10, 'reports', null, false))->toBeFalse();
});

it('authorization decision logic — rejects when owner is 0 (orphaned row) for non-admin', function () {
	// Cast: (int) null === 0; owner === 0 but user >= 1, so unequal.
	expect(decide_authorize(5, 10, 'reports', 0, false))->toBeFalse();
});

// IDOR attack scenarios
// Scenario: attacker is user 7, target is user 5's report id 42.
// Without this helper, report_edit.php accepts id=42 and updates.
// With this helper, the update is rejected.

it('IDOR attack scenarios — blocks cross-user report modification', function () {
	$attacker = 7;
	$victim_report_id = 42;
	$victim_user_id = 5;

	expect(decide_authorize($attacker, $victim_report_id, 'reports', $victim_user_id, false))
		->toBeFalse();
});

it('IDOR attack scenarios — blocks cross-user graph_tree modification', function () {
	expect(decide_authorize(7, 42, 'graph_tree', 5, false))->toBeFalse();
});

it('IDOR attack scenarios — blocks enumeration attack via non-existent resource id', function () {
	// Non-existent rows return null owner; must be false (don't leak existence).
	expect(decide_authorize(7, 999999, 'reports', null, false))->toBeFalse();
});
