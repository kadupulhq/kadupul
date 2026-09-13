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

namespace DbCheckReconnectByValueTest;

/*
 * 1.2.31 declared db_check_reconnect($db_conn = false, $log = true), and plugins
 * and older cactid.php copies call it with a literal. A by-reference first
 * parameter turns that call into an Error, so the public function stays by value.
 */

function cacti_sizeof($value) {
	return count($value);
}

function db_fetch_cell($sql, $column = '', $log = true, $connection = false) {
	return $GLOBALS['reconnect_by_value_healthy'] ? 1 : false;
}

function db_close(&$connection = false) {
	return true;
}

function db_connect_real(...$args) {
	return $GLOBALS['reconnect_by_value_replacement'];
}

$source = file_get_contents(dirname(__DIR__, 3) . '/lib/database.php');

if ($source === false || preg_match_all('/^function db_check_reconnect\w*\(.*?^}\R/ms', $source, $matches) < 1) {
	throw new \RuntimeException('Unable to extract db_check_reconnect() from lib/database.php');
}

eval('namespace DbCheckReconnectByValueTest;' . implode("\n", $matches[0])); // nosemgrep: php.lang.security.eval-use.eval-use

beforeEach(function () {
	$GLOBALS['config']['base_path']              = '/nonexistent-cacti-test';
	$GLOBALS['database_details']                 = array();
	$GLOBALS['database_hostname']                = 'database';
	$GLOBALS['database_username']                = 'cacti';
	$GLOBALS['database_password']                = 'secret';
	$GLOBALS['database_default']                 = 'cacti';
	$GLOBALS['database_type']                    = 'mysql';
	$GLOBALS['reconnect_by_value_healthy']       = false;
	$GLOBALS['reconnect_by_value_replacement']   = new \stdClass();
});

test('a literal first argument is accepted, as in 1.2.31', function () {
	expect(db_check_reconnect(false, false))->toBeTrue();

	$GLOBALS['reconnect_by_value_healthy'] = true;

	expect(db_check_reconnect(false))->toBeTrue();
});

test('the public function reports a failed reconnect', function () {
	$GLOBALS['reconnect_by_value_replacement'] = false;

	expect(db_check_reconnect(false, false))->toBeFalse();
});

test('the public function leaves the caller variable alone, as in 1.2.31', function () {
	$old    = new \stdClass();
	$caller = $old;

	expect(db_check_reconnect($caller, false))->toBeTrue()
		->and($caller)->toBe($old);
});

test('the handle variant still hands the replacement connection back', function () {
	$GLOBALS['database_details'] = array();
	$caller                      = new \stdClass();

	expect(db_check_reconnect_handle($caller, false))->toBeTrue()
		->and($caller)->toBe($GLOBALS['reconnect_by_value_replacement']);
});
