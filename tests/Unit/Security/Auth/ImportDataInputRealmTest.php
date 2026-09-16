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
 * A Data Input Method's input string is a command the poller runs. A web
 * user who holds only an import realm must not create one, or overwrite an
 * existing one by reusing its hash. The realm is read once, before the
 * import starts, straight from the realm tables: is_realm_allowed() can end
 * an invalidated session, which would abort the import partway through.
 *
 * import_data_input_realm_allowed() and xml_to_data_input_method() are
 * extracted from lib/import.php and run with their database helpers stubbed
 * in this namespace, so other unit tests that load lib/ files do not collide.
 */

namespace ImportDataInputRealmTest;

$importSource = file_get_contents(dirname(__DIR__, 4) . '/lib/import.php');

if (!function_exists(__NAMESPACE__ . '\xml_to_data_input_method')) {
	preg_match('/^function import_data_input_realm_allowed\(.*?^}\n/ms', $importSource, $helper);
	preg_match('/^function xml_to_data_input_method\(.*?^}\n/ms', $importSource, $method);
	preg_match('/^function import_xml_record_refused\(.*?^}\n/ms', $importSource, $record);

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . ($helper[0] ?? '') . $method[0] . ($record[0] ?? ''));
}

if (!defined('MESSAGE_LEVEL_WARN')) {
	define('MESSAGE_LEVEL_WARN', 2);
}

function db_fetch_cell_prepared($sql, $params = array()) {
	if (strpos($sql, 'user_auth_realm') !== false) {
		$GLOBALS['idr_realm_queries'][] = $params;
		$GLOBALS['idr_realm_sql'][]     = $sql;

		return $GLOBALS['idr_realm_row'];
	}

	return strpos($sql, 'data_input_fields') !== false ? $GLOBALS['idr_field_id'] : $GLOBALS['idr_method_id'];
}

/* upgrades from before 1.x may lack the group tables */
function read_config_option($name) {
	return $GLOBALS['idr_auth_method'] ?? 1;
}

function db_table_exists($table) {
	return $GLOBALS['idr_group_tables'];
}

function db_fetch_row_prepared($sql, $params = array()) {
	return strpos($sql, 'data_input_fields') !== false ? $GLOBALS['idr_field_row'] : $GLOBALS['idr_method_row'];
}

/* mirrors compare_data(): a new object has nothing to differ from */
function compare_data($save, $previous_data, $table) {
	$different = 0;

	if (count($previous_data)) {
		foreach ($save as $column => $value) {
			if ($previous_data[$column] != $value) {
				$different++;
			}
		}
	}

	return $different;
}

function import_is_base64_encoded($string) {
	return false;
}

function xml_character_decode($string) {
	return $string;
}

function cacti_input_string_is_safe($string) {
	return true;
}

function parse_xml_hash($hash) {
	return array('hash' => substr($hash, -32));
}

function sql_save($save, $table) {
	$GLOBALS['idr_saves'][] = array($table, $save);

	return empty($save['id']) ? 99 : $save['id'];
}

function update_replication_crc($poller_id, $variable) {
}

function generate_data_input_field_sequences($input_string, $data_input_id) {
}

/* the import must never reach this; in a real request it can print and exit */
function is_realm_allowed($realm, $check_user = false) {
	throw new \RuntimeException('is_realm_allowed() can end the session mid-import');
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {
	$GLOBALS['idr_log'][] = $message;
}

function raise_message($message_id, $message = '', $message_level = 0) {
	$GLOBALS['idr_messages'][$message_id] = $message;
}

function html_escape($string) {
	return htmlspecialchars($string, ENT_QUOTES);
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

$methodHash = '95ed0993eb3095f137d3ab3d3dcbcd9c';
$fieldHash  = 'hash_070019e3b8b45a4b1a4b3e8e6a2e8f5c7d1d2e';

$importMethod = function (string $inputString, ?bool $allowed) use ($methodHash, $fieldHash): array {
	$xml = array(
		'name'         => 'Unix - Get <Load> Average',
		'type_id'      => '1',
		'input_string' => $inputString,
		'fields'       => array(
			$fieldHash => array('name' => '(Optional) Log Path', 'data_name' => 'log_path')
		)
	);

	$hash_cache = array();

	if ($allowed === null) {
		$result = xml_to_data_input_method($methodHash, $xml, $hash_cache);
	} else {
		$result = xml_to_data_input_method($methodHash, $xml, $hash_cache, $allowed);
	}

	return array($result, $hash_cache);
};

beforeEach(function () {
	$this->savedGlobals = array_intersect_key($GLOBALS, array_flip(array('config', 'preview_only', 'import_debug_info', 'fields_data_input_edit', 'fields_data_input_field_edit', 'fields_data_input_field_edit_1')));
	$this->savedSession = $_SESSION ?? null;

	$GLOBALS['config']['is_web']               = true;
	$GLOBALS['preview_only']                   = false;
	$GLOBALS['import_debug_info']              = array();
	$GLOBALS['fields_data_input_edit']         = array('name' => array(), 'type_id' => array(), 'input_string' => array());
	$GLOBALS['fields_data_input_field_edit']   = array('name' => array(), 'data_name' => array());
	$GLOBALS['fields_data_input_field_edit_1'] = array();

	$_SESSION = array('sess_user_id' => 5);

	$GLOBALS['idr_auth_method']   = 1;
	$GLOBALS['idr_realm_row']     = false;
	$GLOBALS['idr_realm_queries'] = array();
	$GLOBALS['idr_realm_sql']     = array();
	$GLOBALS['idr_group_tables']  = true;
	$GLOBALS['idr_method_id']     = 9;
	$GLOBALS['idr_method_row']    = array('id' => 9, 'hash' => '95ed0993eb3095f137d3ab3d3dcbcd9c', 'name' => 'Unix - Get <Load> Average', 'type_id' => '1', 'input_string' => 'perl <path_cacti>/scripts/loadavg.pl');
	$GLOBALS['idr_field_id']      = 31;
	$GLOBALS['idr_field_row']     = array('id' => 31, 'hash' => substr('hash_070019e3b8b45a4b1a4b3e8e6a2e8f5c7d1d2e', -32), 'data_input_id' => 9, 'name' => '(Optional) Log Path', 'data_name' => 'log_path');
	$GLOBALS['idr_saves']         = array();
	$GLOBALS['idr_log']           = array();
	$GLOBALS['idr_messages']      = array();
});

afterEach(function () {
	foreach (array('config', 'preview_only', 'import_debug_info', 'fields_data_input_edit', 'fields_data_input_field_edit', 'fields_data_input_field_edit_1') as $name) {
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

test('a web import without the Data Input Methods realm leaves an existing command unchanged', function () use ($importMethod, $methodHash) {
	[, $hash_cache] = $importMethod('/tmp/evil --run', false);

	expect($GLOBALS['idr_saves'])->toBe(array())
		->and($hash_cache['data_input_method'][$methodHash])->toBe(9)
		->and($GLOBALS['import_debug_info']['result'])->toBe('fail')
		->and($GLOBALS['idr_messages'])->toHaveCount(1)
		->and(implode('', $GLOBALS['idr_messages']))->toContain('Unix - Get &lt;Load&gt; Average')
		->and(implode("\n", $GLOBALS['idr_log']))->toContain($methodHash);
});

test('a web import without the realm does not create a new method', function () use ($importMethod, $methodHash) {
	$GLOBALS['idr_method_id']  = false;
	$GLOBALS['idr_method_row'] = array();
	$GLOBALS['idr_field_id']   = false;
	$GLOBALS['idr_field_row']  = array();

	[, $hash_cache] = $importMethod('perl <path_cacti>/scripts/loadavg.pl', false);

	expect($GLOBALS['idr_saves'])->toBe(array())
		->and($hash_cache['data_input_method'][$methodHash])->toBeFalse()
		->and($GLOBALS['import_debug_info']['result'])->toBe('fail')
		->and($GLOBALS['idr_messages'])->toHaveCount(1);
});

test('a web import without the realm reuses an identical existing method quietly', function () use ($importMethod, $methodHash) {
	[, $hash_cache] = $importMethod('perl <path_cacti>/scripts/loadavg.pl', false);

	expect($GLOBALS['idr_saves'])->toBe(array())
		->and($hash_cache['data_input_method'][$methodHash])->toBe(9)
		->and($GLOBALS['import_debug_info']['result'])->toBe('success')
		->and($GLOBALS['idr_messages'])->toBe(array())
		->and($GLOBALS['idr_log'])->toBe(array());
});

test('the preview marks a method the import would skip', function () use ($importMethod) {
	$GLOBALS['preview_only'] = true;

	$importMethod('/tmp/evil --run', false);

	expect($GLOBALS['idr_saves'])->toBe(array())
		->and($GLOBALS['import_debug_info']['result'])->toBe('preview')
		->and(implode("\n", $GLOBALS['import_debug_info']['differences']))->toContain('permission to edit Data Input Methods')
		->and($GLOBALS['idr_messages'])->toBe(array());
});

test('a user with the realm still updates the method', function () use ($importMethod) {
	$importMethod('/usr/local/bin/new-loadavg', true);

	expect($GLOBALS['idr_saves'][0][0])->toBe('data_input')
		->and($GLOBALS['idr_saves'][0][1]['input_string'])->toBe('/usr/local/bin/new-loadavg')
		->and($GLOBALS['idr_saves'][1][0])->toBe('data_input_fields')
		->and($GLOBALS['import_debug_info']['result'])->toBe('success')
		->and($GLOBALS['idr_messages'])->toBe(array());
});

test('the realm check reads the realm tables for the session user', function () {
	$GLOBALS['idr_realm_row'] = 2;

	expect(import_data_input_realm_allowed())->toBeTrue()
		->and($GLOBALS['idr_realm_queries'])->toBe(array(array(5, 2, 2, 5)))
		->and($GLOBALS['idr_realm_sql'][0])->toContain('user_auth_group_members');

	$GLOBALS['idr_realm_row'] = false;

	expect(import_data_input_realm_allowed())->toBeFalse();
});

test('the realm check reads only the user realm table when the group tables are absent', function () {
	$GLOBALS['idr_group_tables'] = false;
	$GLOBALS['idr_realm_row']    = 2;

	expect(import_data_input_realm_allowed())->toBeTrue()
		->and($GLOBALS['idr_realm_queries'])->toBe(array(array(5, 2)))
		->and($GLOBALS['idr_realm_sql'][0])->not->toContain('user_auth_group');

	$GLOBALS['idr_realm_row'] = false;

	expect(import_data_input_realm_allowed())->toBeFalse();
});

test('a web request without a session user may not write methods', function () {
	$_SESSION = array();

	expect(import_data_input_realm_allowed())->toBeFalse()
		->and($GLOBALS['idr_realm_queries'])->toBe(array());
});

test('CLI and installer imports have no web session and keep writing', function () use ($importMethod) {
	$GLOBALS['config']['is_web'] = false;
	$_SESSION = array();

	expect(import_data_input_realm_allowed())->toBeTrue();

	$importMethod('/usr/local/bin/new-loadavg', null);

	expect($GLOBALS['idr_realm_queries'])->toBe(array())
		->and($GLOBALS['idr_saves'][0][1]['input_string'])->toBe('/usr/local/bin/new-loadavg');
});

test('a session invalidated partway through the import does not abort it', function () use ($importMethod) {
	$GLOBALS['idr_realm_row'] = 2;

	$allowed = import_data_input_realm_allowed();

	/* the administrator revokes the realm and the session ends after the check */
	$GLOBALS['idr_realm_row'] = false;
	$_SESSION = array();

	$importMethod('/usr/local/bin/new-loadavg', $allowed);
	$importMethod('/usr/local/bin/other-loadavg', $allowed);

	expect($GLOBALS['idr_realm_queries'])->toHaveCount(1)
		->and($GLOBALS['idr_saves'][0][1]['input_string'])->toBe('/usr/local/bin/new-loadavg')
		->and($GLOBALS['idr_saves'][2][1]['input_string'])->toBe('/usr/local/bin/other-loadavg');
});

test('a caller that omits the realm result is still gated', function () use ($importMethod) {
	$_SESSION = array();

	$importMethod('/tmp/evil --run', null);

	expect($GLOBALS['idr_saves'])->toBe(array())
		->and($GLOBALS['idr_messages'])->toHaveCount(1);
});

test('import_xml_data evaluates the realm once before the import and passes it on', function () use ($importSource) {
	$start = strpos($importSource, 'function import_xml_data(');
	$end   = strpos($importSource, "\nfunction ", $start + 1);
	$body  = substr($importSource, $start, $end - $start);

	$check = strpos($body, '$data_input_allowed = import_data_input_realm_allowed();');
	$loop  = strpos($body, 'foreach ($xml_array as $hash => $hash_array)');
	$call  = strpos($body, "xml_to_data_input_method(\$dep_hash_cache[\$type][\$i]['hash'], \$hash_array, \$hash_cache, \$data_input_allowed, \$refused_hashes);");

	expect($check)->not->toBeFalse()
		->and($check)->toBeLessThan($loop)
		->and($call)->not->toBeFalse()
		->and(substr_count($body, 'import_data_input_realm_allowed('))->toBe(1)
		->and(substr_count($body, ' is_realm_allowed('))->toBe(0);
});


test('explicit no-auth mode does not require a session realm', function () {
    $GLOBALS['idr_auth_method'] = 0;
    $_SESSION = array();
    expect(import_data_input_realm_allowed())->toBeTrue()
        ->and($GLOBALS['idr_realm_queries'])->toBe(array());
});
