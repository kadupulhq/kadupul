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
		'import_xml_record_refused', 'import_xml_refused_reference', 'import_xml_refuse_dependent',
		'import_xml_unresolved_data_input', 'import_package'
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

/* a package hands each file's data to import_xml_data(), so answer per file when one is set */
function xml2array($data) {
	return $GLOBALS['ifl_xml_files'][$data] ?? $GLOBALS['ifl_xml'];
}

function import_validate_signature($xmlfile) : bool {
	return true;
}

function import_read_package_data($xmlfile, &$public_key) {
	$public_key = str_repeat('k', 300);

	return $GLOBALS['ifl_package'];
}

function openssl_verify($data, $signature, $public_key, $algorithm = 0) {
	return 1;
}

/* the hash cache query sees rows saved by an earlier import, as the database would */
function db_fetch_assoc($sql) {
	$rows  = $GLOBALS['ifl_existing'];
	$types = array('data_input' => 'data_input_method', 'data_input_fields' => 'data_input_field', 'data_template' => 'data_template', 'data_template_rrd' => 'data_template_item');

	foreach ($GLOBALS['ifl_saves'] as $save) {
		if (isset($types[$save['table']]) && !empty($save['row']['hash'])) {
			$rows[] = array('type' => $types[$save['table']], 'id' => $save['id'], 'hash' => $save['row']['hash']);
		}
	}

	return $rows;
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

/* only reached when a graph template is not skipped; recorded rather than imported */
function xml_to_graph_template($hash, &$xml_array, &$hash_cache, $hash_version, $remove_orphans = false) {
	$GLOBALS['ifl_graph_templates'][] = $hash;

	return array();
}

function db_begin_transaction() {
	return true;
}

function db_commit_transaction() {
	return true;
}

function db_rollback_transaction() {
	return true;
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
	$GLOBALS['ifl_graph_templates'] = array();
	$GLOBALS['ifl_xml_files']       = array();
	$GLOBALS['ifl_package']         = array();
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

/* the template uses a method and field that already exist, so only the repair is in question */
$existingMethod = function () use ($methodHash, $fieldHash): void {
	$GLOBALS['ifl_existing'] = array(
		array('type' => 'data_input_method', 'id' => 9, 'hash' => $methodHash),
		array('type' => 'data_input_field', 'id' => 31, 'hash' => $fieldHash),
	);
};

test('a user without the realm triggers no data input method repair', function () use ($runImport, $templateXml, $dtHash, $existingMethod, $savedTo) {
	$existingMethod();

	$result = $runImport(array('hash_010103' . $dtHash => $templateXml));

	expect($GLOBALS['ifl_repairs'])->toBe(0)
		->and($savedTo('data_template_rrd')[0]['row']['data_input_field_id'])->toBe(31)
		->and(implode("\n", $result['data_template'][0]['differences']))->toContain('repair was skipped')
		->and(implode("\n", $GLOBALS['ifl_log']))->toContain('Skipped the data input method repair')
		->and(implode("\n", $GLOBALS['ifl_messages']))->toContain('repair was skipped');
});

test('a user with the realm still runs the data input method repair', function () use ($runImport, $templateXml, $dtHash, $existingMethod) {
	$existingMethod();
	$GLOBALS['ifl_realm'] = 2;

	$result = $runImport(array('hash_010103' . $dtHash => $templateXml));

	expect($GLOBALS['ifl_repairs'])->toBe(1)
		->and($result['data_template'][0]['differences'] ?? array())->toBe(array())
		->and($GLOBALS['ifl_messages'])->toBe(array());
});

test('the preview shows that the repair would be skipped', function () use ($runImport, $templateXml, $dtHash, $existingMethod) {
	$existingMethod();
	$GLOBALS['preview_only'] = true;

	$result = $runImport(array('hash_010103' . $dtHash => $templateXml));

	expect($GLOBALS['ifl_repairs'])->toBe(0)
		->and(implode("\n", $result['data_template'][0]['differences']))->toContain('repair was skipped')
		->and($GLOBALS['ifl_messages'])->toBe(array());
});

$gtHash     = md5('kadupul import realm graph template');
$gtItemHash = md5('kadupul import realm graph template item');

$graphXml = array(
	'name'  => 'Uptime Probe Graph',
	'graph' => array('title' => '|host_description| - Uptime'),
	'items' => array(
		'hash_100103' . $gtItemHash => array('task_item_id' => 'hash_080103' . $itemHash, 'color_id' => '', 'sequence' => '1')
	)
);

/* no saved row may point a data template, its items or its data at a missing method or field */
$pointsAtNothing = function (): array {
	$broken = array();

	foreach ($GLOBALS['ifl_saves'] as $save) {
		foreach (array('data_input_id', 'data_input_field_id') as $column) {
			if (array_key_exists($column, $save['row']) && empty($save['row'][$column])) {
				$broken[] = $save['table'] . '.' . $column;
			}
		}
	}

	return $broken;
};

test('a refused new method leaves no template row pointing at field id 0', function () use ($runImport, $methodXml, $templateXml, $methodHash, $dtHash, $pointsAtNothing) {
	$result = $runImport(array(
		'hash_030103' . $methodHash => $methodXml('/usr/local/bin/uptime-probe <host>'),
		'hash_010103' . $dtHash     => $templateXml,
	));

	expect($pointsAtNothing())->toBe(array())
		->and($GLOBALS['ifl_saves'])->toBe(array())
		->and($result['data_template'][0]['result'])->toBe('fail')
		->and(implode("\n", $result['data_template'][0]['differences']))->toContain('uses a Data Input Method you do not have permission to edit')
		->and(implode("\n", $GLOBALS['ifl_log']))->toContain("data_template '$dtHash'")
		->and($GLOBALS['ifl_messages'])->toHaveKey('import_data_input_dependent_' . $dtHash);
});

test('an object that uses a skipped template is skipped as well', function () use ($runImport, $methodXml, $templateXml, $graphXml, $methodHash, $dtHash, $gtHash) {
	$result = $runImport(array(
		'hash_030103' . $methodHash => $methodXml('/usr/local/bin/uptime-probe <host>'),
		'hash_010103' . $dtHash     => $templateXml,
		'hash_000103' . $gtHash     => $graphXml,
	));

	expect($GLOBALS['ifl_graph_templates'])->toBe(array())
		->and($GLOBALS['ifl_saves'])->toBe(array())
		->and($result['graph_template'][0]['hash'])->toBe($gtHash)
		->and(implode("\n", $result['graph_template'][0]['differences']))->toContain('uses a Data Input Method you do not have permission to edit');
});

test('the preview marks a template that would be skipped', function () use ($runImport, $methodXml, $templateXml, $methodHash, $dtHash) {
	$GLOBALS['preview_only'] = true;

	$result = $runImport(array(
		'hash_030103' . $methodHash => $methodXml('/usr/local/bin/uptime-probe <host>'),
		'hash_010103' . $dtHash     => $templateXml,
	));

	expect($GLOBALS['ifl_saves'])->toBe(array())
		->and($result['data_template'][0]['result'])->toBe('preview')
		->and(implode("\n", $result['data_template'][0]['differences']))->toContain('uses a Data Input Method you do not have permission to edit')
		->and($GLOBALS['ifl_messages'])->toBe(array());
});

test('a user with the realm imports the graph template that uses the method', function () use ($runImport, $methodXml, $templateXml, $graphXml, $methodHash, $dtHash, $gtHash, $pointsAtNothing) {
	$GLOBALS['ifl_realm'] = 2;

	$runImport(array(
		'hash_030103' . $methodHash => $methodXml('/usr/local/bin/uptime-probe <host>'),
		'hash_010103' . $dtHash     => $templateXml,
		'hash_000103' . $gtHash     => $graphXml,
	));

	expect($GLOBALS['ifl_graph_templates'])->toBe(array($gtHash))
		->and($pointsAtNothing())->toBe(array());
});

if (!defined('OPENSSL_ALGO_SHA1')) {
	define('OPENSSL_ALGO_SHA1', 1);
	define('OPENSSL_ALGO_SHA256', 7);
}

/* each file name maps to that file's parsed XML, in package order; $decision is what the installer passes */
$runPackage = function (array $files, $decision = null) {
	$package_files = array();

	foreach ($files as $name => $xml) {
		$data = '<' . $name . '/>';

		$GLOBALS['ifl_xml_files'][$data] = $xml;

		$package_files[] = array('name' => $name, 'data' => base64_encode($data), 'filesignature' => base64_encode('signature'));
	}

	$GLOBALS['ifl_package'] = array('info' => array(), 'files' => array('file' => $package_files));

	if ($decision === null) {
		return import_package('package.xml.gz', 1, false, false, false, false, false);
	}

	return import_package('package.xml.gz', 1, false, false, false, false, false, array(), array(), '', false, $decision);
};

test('a method refused in one package file skips a template that uses it in a later file', function () use ($runPackage, $methodXml, $templateXml, $methodHash, $dtHash, $pointsAtNothing) {
	$runPackage(array(
		'method.xml'   => array('hash_030103' . $methodHash => $methodXml('/usr/local/bin/uptime-probe <host>')),
		'template.xml' => array('hash_010103' . $dtHash => $templateXml),
	));

	expect($pointsAtNothing())->toBe(array())
		->and($GLOBALS['ifl_saves'])->toBe(array())
		->and(implode("\n", $GLOBALS['ifl_log']))->toContain("data_template '$dtHash'")
		->and($GLOBALS['ifl_messages'])->toHaveKey('import_data_input_dependent_' . $dtHash);
});

test('a template in an earlier package file than its refused method is skipped as well', function () use ($runPackage, $methodXml, $templateXml, $methodHash, $dtHash, $pointsAtNothing) {
	$runPackage(array(
		'template.xml' => array('hash_010103' . $dtHash => $templateXml),
		'method.xml'   => array('hash_030103' . $methodHash => $methodXml('/usr/local/bin/uptime-probe <host>')),
	));

	expect($pointsAtNothing())->toBe(array())
		->and($GLOBALS['ifl_saves'])->toBe(array())
		->and(implode("\n", $GLOBALS['ifl_log']))->toContain("data_template '$dtHash'")
		->and($GLOBALS['ifl_messages'])->toHaveKey('import_data_input_dependent_' . $dtHash);
});

test('a user with the realm imports a package whose template uses a method from an earlier file', function () use ($runPackage, $methodXml, $templateXml, $methodHash, $dtHash, $savedTo, $pointsAtNothing) {
	$GLOBALS['ifl_realm'] = 2;

	$runPackage(array(
		'method.xml'   => array('hash_030103' . $methodHash => $methodXml('/usr/local/bin/uptime-probe <host>')),
		'template.xml' => array('hash_010103' . $dtHash => $templateXml),
	));

	expect($pointsAtNothing())->toBe(array())
		->and($savedTo('data_template_rrd')[0]['row']['data_input_field_id'])->toBe($savedTo('data_input_fields')[0]['id'])
		->and($GLOBALS['ifl_messages'])->toBe(array());
});

test('a user with the realm still imports a template whose method comes in a later file', function () use ($runPackage, $methodXml, $templateXml, $methodHash, $dtHash, $savedTo) {
	$GLOBALS['ifl_realm'] = 2;

	$runPackage(array(
		'template.xml' => array('hash_010103' . $dtHash => $templateXml),
		'method.xml'   => array('hash_030103' . $methodHash => $methodXml('/usr/local/bin/uptime-probe <host>')),
	));

	expect($savedTo('data_template'))->toHaveCount(1)
		->and($savedTo('data_input'))->toHaveCount(1)
		->and($GLOBALS['ifl_messages'])->toBe(array());
});

test('a standalone template import without the realm skips a method it cannot resolve', function () use ($runImport, $templateXml, $dtHash, $pointsAtNothing) {
	$result = $runImport(array('hash_010103' . $dtHash => $templateXml));

	expect($pointsAtNothing())->toBe(array())
		->and($GLOBALS['ifl_saves'])->toBe(array())
		->and($result['data_template'][0]['result'])->toBe('fail');
});

test('the installer imports a shipped package with its methods and templates without a session realm', function () use ($runPackage, $methodXml, $templateXml, $methodHash, $dtHash, $savedTo, $pointsAtNothing) {
	/* install/install.php runs in the web SAPI with no session user during an install or upgrade */
	$_SESSION = array();

	$runPackage(array(
		'method.xml'   => array('hash_030103' . $methodHash => $methodXml('/usr/local/bin/uptime-probe <host>')),
		'template.xml' => array('hash_010103' . $dtHash => $templateXml),
	), true);

	expect($savedTo('data_input'))->toHaveCount(1)
		->and($savedTo('data_template'))->toHaveCount(1)
		->and($pointsAtNothing())->toBe(array())
		->and($GLOBALS['ifl_repairs'])->toBe(2)
		->and($GLOBALS['ifl_messages'])->toBe(array());
});

test('a web package import without the realm is still gated when no decision is passed', function () use ($runPackage, $methodXml, $templateXml, $methodHash, $dtHash) {
	$_SESSION = array();

	$runPackage(array(
		'method.xml'   => array('hash_030103' . $methodHash => $methodXml('/usr/local/bin/uptime-probe <host>')),
		'template.xml' => array('hash_010103' . $dtHash => $templateXml),
	));

	expect($GLOBALS['ifl_saves'])->toBe(array())
		->and($GLOBALS['ifl_messages'])->toHaveKey('import_data_input_dependent_' . $dtHash);
});

test('the installer passes its own decision only from an installer entry point', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/installer.php');

	$start = strpos($source, 'private function installTemplate(');
	$end   = strpos($source, "\n\t}\n", $start);
	$body  = substr($source, $start, $end - $start);

	expect($body)->toContain("\$data_input_allowed = (defined('IN_CACTI_INSTALL') || !\$config['is_web']) ? true : null;")
		->and($body)->toContain("\$info['class'], false, \$data_input_allowed);");
});
