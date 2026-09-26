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

namespace AuditDatabaseNullDefaultTest;

$source = file_get_contents(dirname(__DIR__, 3) . '/cli/audit_database.php');

if ($source === false || preg_match('/^function make_column_props\(.*?^}\R/ms', $source, $matches) !== 1) {
	throw new \RuntimeException('Unable to extract make_column_props() from cli/audit_database.php');
}

eval('namespace AuditDatabaseNullDefaultTest;' . $matches[0]); // nosemgrep: php.lang.security.eval-use.eval-use

test('a normalized SQL NULL default does not leak the comparison sentinel into DDL', function () {
	$column = array(
		'table_default' => "\x01NULL",
		'table_null'    => 'YES',
		'table_extra'   => '',
	);

	$properties = make_column_props($column);

	expect($properties)->toBe('')
		->and($properties)->not->toContain("\x01NULL")
		->and($column['table_default'])->toBeNull();
});
