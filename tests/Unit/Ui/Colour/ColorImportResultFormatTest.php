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

namespace ColorImportResultFormatTest;

/*
 * The color import result lines keep the 1.2.31 text: values quoted and listed
 * in header column order. The prepared statements, output escaping and hex check
 * stay in place.
 */

function cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function html_escape($string) {
	return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8');
}

function isset_request_var($name) {
	return $GLOBALS['color_import_allow_update'];
}

function db_fetch_row_prepared($sql, $params = array()) {
	return isset($GLOBALS['color_import_rows'][$params[0]]) ? array('hex' => $params[0]) : array();
}

function db_execute_prepared($sql, $params = array()) {
	$GLOBALS['color_import_writes'][] = $params;

	return true;
}

$source = file_get_contents(dirname(__DIR__, 4) . '/color.php');

if ($source === false || preg_match('/^function color_import_processor\(.*?^}\R/ms', $source, $matches) !== 1) {
	throw new \RuntimeException('Unable to extract color_import_processor() from color.php');
}

eval('namespace ColorImportResultFormatTest;' . $matches[0]); // nosemgrep: php.lang.security.eval-use.eval-use

beforeEach(function () {
	$GLOBALS['color_import_allow_update'] = false;
	$GLOBALS['color_import_rows']         = array();
	$GLOBALS['color_import_writes']       = array();
});

test('an exported colors file reports rows in the 1.2.31 format', function () {
	$lines = array("\"name\",\"hex\"\n", "\"White\",\"FFFFFF\"\n", "\"Black\",\"000000\"\n");

	expect(color_import_processor($lines))->toBe(array(
		'<b>HEADER LINE PROCESSED OK</b>:  <br>Columns found where: (name, hex)<br>',
		"INSERT SUCCEEDED: ('White','FFFFFF')",
		"INSERT SUCCEEDED: ('Black','000000')",
	))->and($GLOBALS['color_import_writes'])->toBe(array(array('FFFFFF', 'White'), array('000000', 'Black')));
});

test('existing and updated rows keep the 1.2.31 wording', function () {
	$GLOBALS['color_import_rows'] = array('FFFFFF' => 'White');
	$lines = array("hex,name\n", "FFFFFF,White\n");

	expect(color_import_processor($lines))->toBe(array(
		'<b>HEADER LINE PROCESSED OK</b>:  <br>Columns found where: (hex, name)<br>',
		"<strong>INSERT SKIPPED, EXISTING:</strong> ('FFFFFF','White')",
	));

	$GLOBALS['color_import_allow_update'] = true;

	expect(color_import_processor($lines)[1])->toBe("INSERT SUCCEEDED: ('FFFFFF','White')");
});

test('a missing header keeps the 1.2.31 error text', function () {
	$lines = array("hex\n", "FFFFFF\n");

	expect(color_import_processor($lines))->toBe(array(
		'<b>HEADER LINE PROCESSING ERROR</b>: Missing required field <br>Columns found where:(hex)<br>',
	))->and($GLOBALS['color_import_writes'])->toBe(array());
});

test('invalid hex values and markup are still refused or escaped', function () {
	$lines = array("name,hex\n", "\"<b>x</b>\",\"ZZZZZZ\"\n", "\"<b>y</b>\",\"00FF00\"\n");

	expect(color_import_processor($lines))->toBe(array(
		'<b>HEADER LINE PROCESSED OK</b>:  <br>Columns found where: (name, hex)<br>',
		"<strong>INSERT SKIPPED, INVALID HEX:</strong> ('&lt;b&gt;x&lt;/b&gt;','ZZZZZZ')",
		"INSERT SUCCEEDED: ('&lt;b&gt;y&lt;/b&gt;','00FF00')",
	))->and($GLOBALS['color_import_writes'])->toBe(array(array('00FF00', '<b>y</b>')));
});
