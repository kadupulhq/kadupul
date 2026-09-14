<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * get_basic_auth_username() reads the basic->shortform mapfile with
 * str_getcsv($r, ',', '"', '\\'), the same separator, enclosure and escape
 * PHP defaulted to before 8.4 deprecated leaving $escape out. This exercises
 * the real mapfile branch, which no other test covers, against a basic
 * column containing a quoted backslash (a Windows DOMAIN\user value).
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

test('a basic mapfile entry with a domain backslash still maps to its shortform account', function () {
	$mapfile = tempnam(sys_get_temp_dir(), 'cacti_basic_map_');

	// get_basic_auth_username() doubles backslashes in the incoming header
	// before comparing, so the mapfile's basic column carries two as well.
	file_put_contents($mapfile, "\"CORP\\\\jdoe\",jdoe\n");

	try {
		$result = cacti_test_run_auth_entry_probe(array(
			'config' => array(
				'auth_method'        => 2,
				'path_basic_mapfile' => $mapfile,
			),
			'server' => array('PHP_AUTH_USER' => 'CORP\jdoe'),
			'call'   => array('type' => 'get_basic_auth_username'),
		));
	} finally {
		unlink($mapfile);
	}

	expect($result['return'])->toBe('jdoe');
});

test('an unmatched basic mapfile still falls back to the raw username', function () {
	$mapfile = tempnam(sys_get_temp_dir(), 'cacti_basic_map_');
	file_put_contents($mapfile, "\"CORP\\\\other\",other\n");

	try {
		$result = cacti_test_run_auth_entry_probe(array(
			'config' => array(
				'auth_method'        => 2,
				'path_basic_mapfile' => $mapfile,
			),
			'server' => array('PHP_AUTH_USER' => 'CORP\jdoe'),
			'call'   => array('type' => 'get_basic_auth_username'),
		));
	} finally {
		unlink($mapfile);
	}

	expect($result['return'])->toBe('CORP\\\\jdoe');
});
