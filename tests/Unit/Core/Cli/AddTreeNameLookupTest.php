<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

namespace AddTreeNameLookupTest;

function db_fetch_cell_prepared($sql, $params = array()) {
	return $GLOBALS['tree_name_count'];
}

$source = file_get_contents(dirname(__DIR__, 4) . '/cli/add_tree.php');

if ($source === false || preg_match('/^function database_tree_name_exists\(.*?^}\R/ms', $source, $matches) !== 1) {
	throw new \RuntimeException('Unable to extract database_tree_name_exists() from cli/add_tree.php');
}

eval('namespace AddTreeNameLookupTest;' . $matches[0]); // nosemgrep: php.lang.security.eval-use.eval-use

test('tree duplicate lookup distinguishes existing, missing, and failed queries', function () {
	$GLOBALS['tree_name_count'] = 1;
	expect(database_tree_name_exists('existing'))->toBeTrue();

	$GLOBALS['tree_name_count'] = 0;
	expect(database_tree_name_exists('new'))->toBeFalse();

	$GLOBALS['tree_name_count'] = false;
	expect(database_tree_name_exists('unknown'))->toBeNull();
});
