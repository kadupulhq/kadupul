<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
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
 * api_tree_create_node(), api_tree_delete_node(), api_tree_rename_node(),
 * api_tree_copy_node() and api_tree_move_node() validated only that tree_id was
 * a positive number, so any authenticated user could change nodes in another
 * user's tree (IDOR). Each now rejects an out-of-range tree_id and then checks
 * is_tree_allowed($tree_id), bailing out before any write when the caller may
 * not touch the tree.
 */

$src = file_get_contents(dirname(__DIR__, 2) . '/lib/api_tree.php');
expect($src)->toBeString();

function _tree_fn_body(string $src, string $fn) : string {
	$start = strpos($src, "function $fn(");
	expect($start)->not->toBeFalse();
	$end = strpos($src, "\nfunction ", $start + 1);

	return substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
}

// the first statement that reads or writes tree data in each function, and the
// check that rejects an out-of-range tree_id
$cases = array(
	'api_tree_create_node' => array('bail' => 'return false;', 'sink' => 'sql_save(',   'idcheck' => 'if ($tree_id <= 0) {'),
	'api_tree_delete_node' => array('bail' => 'return;',       'sink' => 'db_execute',  'idcheck' => 'if ($tree_id <= 0) {'),
	'api_tree_rename_node' => array('bail' => 'return;',       'sink' => 'db_execute',  'idcheck' => 'if ($tree_id <= 0) {'),
	'api_tree_copy_node'   => array('bail' => 'return;',       'sink' => 'db_fetch_',   'idcheck' => 'if ($tree_id <= 0) {'),
	'api_tree_move_node'   => array('bail' => 'return;',       'sink' => 'db_execute',  'idcheck' => 'if (empty($tree_id) || $tree_id < 0) {'),
);

foreach ($cases as $fn => $case) {
	test("$fn refuses trees the user may not access", function () use ($src, $fn, $case) {
		$body = _tree_fn_body($src, $fn);

		// the permission guard exists and bails immediately inside its own block,
		// so an unrelated early return elsewhere can't be mistaken for the guard
		$guard = strpos($body, 'if (!is_tree_allowed($tree_id)) {');
		expect($guard)->not->toBeFalse();

		$block = substr($body, $guard, strpos($body, '}', $guard) - $guard);
		expect($block)->toContain($case['bail']);

		// the guard runs before the first write to the tree
		$sink = strpos($body, $case['sink']);

		if ($sink !== false) {
			expect($guard)->toBeLessThan($sink);
		}
	});

	test("$fn rejects an out-of-range tree_id before the permission lookup", function () use ($src, $fn, $case) {
		$body = _tree_fn_body($src, $fn);

		// is_tree_allowed() caches into $_SESSION and hits the database, so a
		// 0/negative id must fail as bad input first
		$idcheck = strpos($body, $case['idcheck']);
		$guard   = strpos($body, 'if (!is_tree_allowed($tree_id)) {');

		expect($idcheck)->not->toBeFalse();
		expect($guard)->not->toBeFalse();
		expect($idcheck)->toBeLessThan($guard);
	});
}
