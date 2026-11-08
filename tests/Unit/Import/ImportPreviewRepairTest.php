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

/*
 * import_xml_data() repairs legacy system data input fields and mappings after
 * a template import.  A preview must not run that repair, because the repair
 * deletes and reassigns rows; a real import keeps it.  The production function
 * runs against a minimal data input method document with the object writers
 * stubbed.
 */

namespace ImportPreviewRepair;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

$source = file_get_contents(dirname(__DIR__, 3) . '/lib/import.php');
foreach (array('import_xml_data', 'parse_xml_hash', 'check_hash_type', 'check_hash_version') as $function) {
	eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $function));
}

if (!defined('CACTI_VERSION')) {
	define('CACTI_VERSION', '1.2.32');
}

foreach (array('POLLER_VERBOSITY_LOW' => 2, 'POLLER_VERBOSITY_HIGH' => 4, 'MESSAGE_LEVEL_WARN' => 2) as $name => $value) {
	if (!defined($name)) {
		define($name, $value);
	}
}

function __($text, ...$args) { return $text; }
function cacti_sizeof($value) { return is_array($value) || $value instanceof \Countable ? count($value) : 0; }
function cacti_count($value) { return is_array($value) ? count($value) : 0; }
function cacti_log(...$args) { $GLOBALS['ipr_logs'][] = $args[0]; }
function raise_message(...$args) { $GLOBALS['ipr_raised'][] = $args[0]; }
function cacti_version_compare($a, $b, $operator) { return version_compare($a, $b, $operator); }
function db_begin_transaction() { return true; }
function db_commit_transaction() { return true; }
function db_rollback_transaction() { return true; }
function xml2array($xml) { return array('hash_033eb92bb845b9660a7445cf9740726522' => array('name' => 'Get SNMP Data')); }
function db_fetch_assoc($sql) { return array(); }
function import_data_input_realm_allowed() { return true; }
function xml_detect_ignorable_hash_cache($hash, $hash_array) { return false; }
function xml_to_data_input_method($hash, $hash_array, &$hash_cache, $allowed, &$refused) {
	global $import_debug_info;

	$import_debug_info = array('type' => 'data_input_method', 'title' => 'Get SNMP Data', 'differences' => array());

	return array();
}
function repair_system_data_input_methods($step = 'import') { $GLOBALS['ipr_repairs']++; }

beforeEach(function () {
	global $config, $hash_type_codes, $cacti_version_codes, $preview_only;

	// import_xml_data() includes xml.php; the document parser is stubbed below.
	$config              = array('library_path' => dirname(__DIR__, 3) . '/lib');
	$hash_type_codes     = array('data_input_method' => '03');
	$cacti_version_codes = array(CACTI_VERSION => '0101');
	$GLOBALS['ipr_repairs'] = 0;
	$GLOBALS['ipr_logs']    = array();
	$GLOBALS['ipr_raised']  = array();
	$GLOBALS['import_messages']   = array();
	$GLOBALS['import_debug_info'] = array();
	$GLOBALS['legacy_template']   = false;
	$preview_only = false;
});

function ipr_import($preview, $allowed) {
	global $preview_only;

	$preview_only = $preview;
	$xml          = '<hash_033eb92bb845b9660a7445cf9740726522><name>Get SNMP Data</name></hash_033eb92bb845b9660a7445cf9740726522>';

	return import_xml_data($xml, false, 1, false, false, array(), '', $allowed);
}

test('a preview describes the pending repair and leaves the data input fields alone', function () {
	$info = ipr_import(true, true);

	expect($GLOBALS['ipr_repairs'])->toBe(0);
	expect($info['data_input_method'][0]['differences'])->toBe(array('Legacy system input fields and mappings will be checked for repairs only when the template is imported.'));
});

test('a real import still repairs the legacy data input fields', function () {
	$info = ipr_import(false, true);

	expect($GLOBALS['ipr_repairs'])->toBe(1);
	expect($info['data_input_method'][0]['differences'])->toBe(array());
});

test('without the data input permission neither a preview nor an import repairs', function ($preview) {
	$info = ipr_import($preview, false);

	expect($GLOBALS['ipr_repairs'])->toBe(0);
	expect($info['data_input_method'][0]['differences'])->toBe(array('The Data Input Method repair was skipped because you do not have permission to edit Data Input Methods.'));
	expect($GLOBALS['ipr_raised'])->toBe($preview ? array() : array('import_data_input_repair'));
})->with(array('preview' => true, 'import' => false));
