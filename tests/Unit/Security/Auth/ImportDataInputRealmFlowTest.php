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
 * Runs the real import_xml_data() together with the Data Input Method and
 * Data Template importers from lib/import.php. The database, logging and
 * session helpers are stubbed in this namespace, and every row an importer
 * saves is recorded instead of written, so a test can check what an import
 * would have left in the database.
 */

namespace ImportDataInputRealmFlowTest;

$root = dirname(__DIR__, 4);

if (!function_exists(__NAMESPACE__ . '\import_xml_data')) {
	$source = file_get_contents($root . '/lib/import.php');
	$code   = '';

	$functions = array(
		'import_xml_data', 'import_data_input_realm_allowed', 'xml_to_data_input_method', 'xml_to_data_template',
		'xml_detect_ignorable_hash_cache', 'compare_data', 'resolve_hash_to_id', 'parse_xml_hash', 'check_hash_type',
		'check_hash_version', 'import_validate_data_source_item', 'xml_character_decode', 'import_is_base64_encoded',
		'import_xml_record_refused', 'import_xml_refused_reference', 'import_xml_refuse_dependent'
	);

	/* helpers a later fix adds are simply absent before it */
	foreach ($functions as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\n/ms', $source, $match)) {
			$code .= $match[0];
		}
	}

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $code);
}

$arrays = file_get_contents($root . '/include/global_arrays.php');
preg_match('/^\$cacti_version_codes = (array\(.*?^\));\n/ms', $arrays, $versions);
preg_match('/^\$hash_type_codes = (array\(.*?^\));\n/ms', $arrays, $types);

// test-only eval of array literals read from this repository
$versionCodes = eval('return ' . $versions[1] . ';');
$typeCodes    = eval('return ' . $types[1] . ';');

foreach (array(
	'CACTI_VERSION'          => trim(file_get_contents($root . '/include/cacti_version')),
	'POLLER_VERBOSITY_LOW'    => 2,
	'POLLER_VERBOSITY_MEDIUM' => 3,
	'POLLER_VERBOSITY_HIGH'   => 4,
	'MESSAGE_LEVEL_WARN'      => 2,
) as $constant => $value) {
	if (!defined($constant)) {
		define($constant, $value);
	}
}

$libraryDir = sys_get_temp_dir() . '/import-realm-flow-lib';

if (!is_dir($libraryDir)) {
	mkdir($libraryDir, 0700, true);
}

/* import_xml_data() includes lib/xml.php; xml2array() is stubbed below */
file_put_contents($libraryDir . '/xml.php', "<?php\n");

function xml2array($data) {
	return $GLOBALS['ifl_xml'];
}

function db_fetch_assoc($sql) {
	return $GLOBALS['ifl_existing'];
}

function db_fetch_cell_prepared($sql, $params = array()) {
	if (strpos($sql, 'user_auth_realm') !== false) {
		return $GLOBALS['ifl_realm'];
	} elseif (strpos($sql, 'data_source_profiles') !== false) {
		return 300;
	}

	return false;
}

function db_fetch_cell($sql) {
	return 1;
}

function db_fetch_row_prepared($sql, $params = array()) {
	return array();
}

function db_execute_prepared($sql, $params = array()) {
	return true;
}

function db_execute($sql) {
	return true;
}

function db_table_exists($table) {
	return true;
}

function db_get_table_column_types($table) {
	return array();
}

function sql_save($save, $table, $key_cols = 'id', $autoinc = true) {
	$id = ++$GLOBALS['ifl_next_id'];

	$GLOBALS['ifl_saves'][] = array('table' => $table, 'row' => $save, 'id' => $id);

	return $id;
}

function repair_system_data_input_methods($step = 'import') {
	$GLOBALS['ifl_repairs']++;
}

/* the shell metacharacter rule, without the site override the real helper reads */
function cacti_input_string_is_safe($input_string) {
	return !preg_match('/[;&|`$]/', $input_string);
}

function update_replication_crc($poller_id, $variable) {
}

function generate_data_input_field_sequences($input_string, $data_input_id) {
}

function api_plugin_hook_function($name, $parameters = '') {
	return $parameters;
}

function graph_template_input_xml_preflight($xml_array) {
	return true;
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {
	$GLOBALS['ifl_log'][] = $message;
}

function raise_message($message_id, $message = '', $message_level = 0) {
	$GLOBALS['ifl_messages'][$message_id] = $message;
}

function html_escape($string) {
	return htmlspecialchars((string) $string, ENT_QUOTES);
}

function clean_up_lines($string) {
	return preg_replace('/[\r\n]+/', ' ', (string) $string);
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function cacti_count($array) {
	return is_array($array) ? count($array) : 0;
}

function cacti_version_compare($version1, $version2, $operator = '>') {
	return version_compare($version1, $version2, $operator);
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

$methodHash = md5('kadupul import realm method');
$fieldHash  = md5('kadupul import realm field');
$dtHash     = md5('kadupul import realm template');
$itemHash   = md5('kadupul import realm template item');

$methodXml = function (string $inputString) use ($fieldHash): array {
	return array(
		'name'         => 'Uptime Probe',
		'type_id'      => '1',
		'input_string' => $inputString,
		'fields'       => array(
			'hash_070103' . $fieldHash => array('name' => 'Host', 'data_name' => 'host', 'input_output' => 'in', 'update_rra' => '', 'sequence' => '1', 'type_code' => '', 'regexp_match' => '', 'allow_nulls' => '')
		)
	);
};

$templateXml = array(
	'name'  => 'Uptime Probe Template',
	'ds'    => array('name' => '|host_description| - Uptime', 'data_input_id' => 'hash_030103' . $methodHash, 'data_source_path' => '', 'rrd_step' => '300', 'active' => 'on'),
	'items' => array(
		'hash_080103' . $itemHash => array('data_source_name' => 'uptime', 'rrd_minimum' => '0', 'rrd_maximum' => 'U', 'data_source_type_id' => '1', 'rrd_heartbeat' => '600', 'data_input_field_id' => 'hash_070103' . $fieldHash)
	),
	'data'  => array(
		'item_000' => array('data_input_field_id' => 'hash_070103' . $fieldHash, 't_value' => '', 'value' => 'localhost')
	)
);

$runImport = function (array $xml) {
	$GLOBALS['ifl_xml'] = $xml;

	$data = '<cacti/>';

	return import_xml_data($data, false, 1);
};

$savedTo = function (string $table): array {
	return array_values(array_filter($GLOBALS['ifl_saves'], function ($save) use ($table) {
		return $save['table'] == $table;
	}));
};

beforeEach(function () use ($typeCodes, $versionCodes, $libraryDir) {
	$names = array('config', 'hash_type_codes', 'cacti_version_codes', 'struct_data_source', 'struct_data_source_item',
		'fields_data_input_edit', 'fields_data_input_field_edit', 'fields_data_input_field_edit_1', 'preview_only',
		'import_debug_info', 'import_messages', 'legacy_template', 'ignorable_hashes');

	$this->savedNames   = $names;
	$this->savedGlobals = array_intersect_key($GLOBALS, array_flip($names));
	$this->savedSession = $_SESSION ?? null;

	$GLOBALS['config']                         = array('is_web' => true, 'library_path' => $libraryDir);
	$GLOBALS['hash_type_codes']                = $typeCodes;
	$GLOBALS['cacti_version_codes']            = $versionCodes;
	$GLOBALS['struct_data_source']             = array_fill_keys(array('name', 'data_source_path', 'data_input_id', 'data_source_profile_id', 'rrd_step', 'active'), array());
	$GLOBALS['struct_data_source_item']        = array_fill_keys(array('data_source_name', 'rrd_minimum', 'rrd_maximum', 'data_source_type_id', 'rrd_heartbeat', 'data_input_field_id'), array());
	$GLOBALS['fields_data_input_edit']         = array_fill_keys(array('name', 'type_id', 'input_string'), array());
	$GLOBALS['fields_data_input_field_edit']   = array_fill_keys(array('name', 'data_name', 'input_output', 'update_rra', 'sequence', 'type_code', 'regexp_match', 'allow_nulls'), array());
	$GLOBALS['fields_data_input_field_edit_1'] = array();
	$GLOBALS['preview_only']                   = false;
	$GLOBALS['import_debug_info']              = array();
	$GLOBALS['import_messages']                = array();
	$GLOBALS['legacy_template']                = false;
	$GLOBALS['ignorable_hashes']               = array();

	$_SESSION = array('sess_user_id' => 5);

	$GLOBALS['ifl_realm']    = false;
	$GLOBALS['ifl_existing'] = array();
	$GLOBALS['ifl_saves']    = array();
	$GLOBALS['ifl_next_id']  = 100;
	$GLOBALS['ifl_repairs']  = 0;
	$GLOBALS['ifl_log']      = array();
	$GLOBALS['ifl_messages'] = array();
});

afterEach(function () {
	foreach ($this->savedNames as $name) {
		if (array_key_exists($name, $this->savedGlobals)) {
			$GLOBALS[$name] = $this->savedGlobals[$name];
		} else {
			unset($GLOBALS[$name]);
		}
	}

	if ($this->savedSession === null) {
		unset($_SESSION);
	} else {
		$_SESSION = $this->savedSession;
	}
});

test('a user with the realm imports the method and a template that uses its field', function () use ($runImport, $methodXml, $templateXml, $methodHash, $dtHash, $savedTo) {
	$GLOBALS['ifl_realm'] = 2;

	$result = $runImport(array(
		'hash_030103' . $methodHash => $methodXml('/usr/local/bin/uptime-probe <host>'),
		'hash_010103' . $dtHash     => $templateXml,
	));

	$field = $savedTo('data_input_fields');
	$rrd   = $savedTo('data_template_rrd');

	expect($result)->toBeArray()
		->and($field)->toHaveCount(1)
		->and($rrd)->toHaveCount(1)
		->and($rrd[0]['row']['data_input_field_id'])->toBe($field[0]['id'])
		->and($savedTo('data_template_data')[0]['row']['data_input_id'])->toBe($savedTo('data_input')[0]['id']);
});

test('a refused input string fails the import cleanly instead of throwing', function () use ($runImport, $methodXml, $templateXml, $methodHash, $dtHash, $savedTo) {
	$GLOBALS['ifl_realm'] = 2;

	$result = $runImport(array(
		'hash_030103' . $methodHash => $methodXml('/usr/local/bin/uptime-probe <host>; touch /tmp/owned'),
		'hash_010103' . $dtHash     => $templateXml,
	));

	expect($result)->toBeFalse()
		->and(implode("\n", $GLOBALS['ifl_log']))->toContain('shell metacharacters')
		->and($savedTo('data_input'))->toBe(array())
		->and($savedTo('data_template_rrd'))->toBe(array());
});
