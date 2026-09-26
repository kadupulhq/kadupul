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

namespace FixMediumintClauseSpacingTest;

function database_quote_identifier($identifier) {
	return '`' . str_replace('`', '``', $identifier) . '`';
}

function db_qstr($value) {
	return "'" . $value . "'";
}

$source = file_get_contents(dirname(__DIR__, 4) . '/cli/fix_mediumint.php');

if ($source === false || preg_match('/^function database_mediumint_column_clause\(.*?^}\R/ms', $source, $matches) !== 1) {
	throw new \RuntimeException('Unable to extract database_mediumint_column_clause() from cli/fix_mediumint.php');
}

eval('namespace FixMediumintClauseSpacingTest;' . $matches[0]); // nosemgrep: php.lang.security.eval-use.eval-use

test('mediumint clauses include a separator after the quoted ALTER TABLE name', function () {
	$clause = database_mediumint_column_clause('poller_id', array(
		'Type'    => 'mediumint(8) unsigned',
		'Extra'   => '',
		'Default' => null,
		'Null'    => 'NO',
	));

	expect($clause)->toStartWith(' MODIFY COLUMN `poller_id` int(10) unsigned NOT NULL');
});
