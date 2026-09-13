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

/*
 * A package holds several template XML files, and import_package() hands
 * each to import_xml_data() in turn. The Data Input Methods realm decision
 * must be taken once for the whole package, so a permission change between
 * two files cannot import one file and refuse the next.
 *
 * import_package() is extracted from lib/import.php and run in this namespace
 * with signature checks and import_xml_data() stubbed. The stub takes its own
 * decision when none is passed in, as the real function does.
 */

namespace ImportDataInputRealmPackageTest;

if (!function_exists(__NAMESPACE__ . '\import_package')) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/import.php');
	preg_match('/^function import_package\(.*?^}\n/ms', $source, $match);

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $match[0]);
}

function import_validate_signature($xmlfile) : bool {
	return true;
}

function import_read_package_data($xmlfile, &$public_key) {
	$public_key = str_repeat('k', 300);

	return array(
		'info'  => array(),
		'files' => array(
			'file' => array(
				array('name' => 'templates/first.xml', 'data' => base64_encode('<first/>'), 'filesignature' => base64_encode('signature')),
				array('name' => 'templates/second.xml', 'data' => base64_encode('<second/>'), 'filesignature' => base64_encode('signature')),
			)
		)
	);
}

function openssl_verify($data, $signature, $public_key, $algorithm = 0) {
	return 1;
}

/* each call answers from the queue, so the realm can change between files */
function import_data_input_realm_allowed() {
	$GLOBALS['ipk_realm_checks']++;

	return array_shift($GLOBALS['ipk_realm_answers']);
}

function import_xml_data(&$xml_data, $import_as_new, $profile_id, $remove_orphans = false, $replace_svalues = false, $import_hashes = array(), $class = '', $data_input_allowed = null) {
	if ($data_input_allowed === null) {
		$data_input_allowed = import_data_input_realm_allowed();
	}

	$GLOBALS['ipk_decisions'][$xml_data] = $data_input_allowed;

	return array();
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

foreach (array('POLLER_VERBOSITY_LOW' => 2, 'POLLER_VERBOSITY_MEDIUM' => 3) as $constant => $value) {
	if (!defined($constant)) {
		define($constant, $value);
	}
}

if (!defined('OPENSSL_ALGO_SHA1')) {
	define('OPENSSL_ALGO_SHA1', 1);
	define('OPENSSL_ALGO_SHA256', 7);
}

beforeEach(function () {
	$this->savedGlobals = array_intersect_key($GLOBALS, array_flip(array('config', 'preview_only')));

	$GLOBALS['config']['base_path'] = sys_get_temp_dir();
	$GLOBALS['preview_only']        = false;

	$GLOBALS['ipk_realm_checks']  = 0;
	$GLOBALS['ipk_realm_answers'] = array();
	$GLOBALS['ipk_decisions']     = array();
});

afterEach(function () {
	foreach (array('config', 'preview_only') as $name) {
		if (array_key_exists($name, $this->savedGlobals)) {
			$GLOBALS[$name] = $this->savedGlobals[$name];
		} else {
			unset($GLOBALS[$name]);
		}
	}
});

test('a package takes one realm decision for every XML file it imports', function () {
	/* the realm is revoked after the first answer */
	$GLOBALS['ipk_realm_answers'] = array(true, false);

	import_package('package.xml.gz', 1, false, false, false, false, false);

	expect($GLOBALS['ipk_decisions'])->toBe(array('<first/>' => true, '<second/>' => true))
		->and($GLOBALS['ipk_realm_checks'])->toBe(1);
});

test('a package without the realm refuses every file alike when the realm is granted partway', function () {
	$GLOBALS['ipk_realm_answers'] = array(false, true);

	import_package('package.xml.gz', 1, false, false, true, false, false);

	expect($GLOBALS['ipk_decisions'])->toBe(array('<first/>' => false, '<second/>' => false))
		->and($GLOBALS['ipk_realm_checks'])->toBe(1);
});
