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

namespace ColumnTypeQuotedEnumTest;

/*
 * Plugins such as evidence declare enum columns with double-quoted values, which
 * MySQL accepts. The DDL type check allows them with the same shape as
 * single-quoted values and still refuses separators, comments, backticks and
 * control characters.
 */

$root = dirname(__DIR__, 3);
$code = '';

foreach (array(array('/lib/functions.php', 'cacti_has_control_chars'), array('/lib/database.php', 'db_is_safe_column_type')) as $wanted) {
	if (preg_match('/^function ' . $wanted[1] . '\(.*?^}\R/ms', file_get_contents($root . $wanted[0]), $match) !== 1) {
		throw new \RuntimeException('Unable to extract ' . $wanted[1] . '()');
	}

	$code .= $match[0];
}

eval('namespace ColumnTypeQuotedEnumTest;' . $code); // nosemgrep: php.lang.security.eval-use.eval-use

test('plugin_evidence enum types are accepted', function () {
	expect(db_is_safe_column_type('enum("yes","no")'))->toBeTrue()
		->and(db_is_safe_column_type('enum("get", "walk", "info", "table")'))->toBeTrue();
});

test('other quoted enum and set forms are accepted', function (string $type) {
	expect(db_is_safe_column_type($type))->toBeTrue();
})->with(array(
	"enum('on','off')",
	"enum('it''s','x')",
	'set("a","b","c")',
	'enum("it\'s")',
	'enum("say ""hi""")',
	'ENUM ( "a" , \'b\' )',
	'varchar(255)',
	'int(10) unsigned',
));

test('injection and malformed types are still refused', function (string $type) {
	expect(db_is_safe_column_type($type))->toBeFalse();
})->with(array(
	'enum("a");DROP TABLE host',
	'enum("a") -- ',
	'enum("a")/* x */',
	'enum("a")#',
	'enum("a`")',
	"enum(\"a\nb\")",
	"enum(\"a\x00\")",
	'enum("a"), ADD COLUMN b int',
	'enum("a") DEFAULT "a"',
	'enum("a" "b")',
	'enum("a)',
	'enum("a\\")',
	'varchar("255")',
	'int(10) "x"',
	'text"',
));
