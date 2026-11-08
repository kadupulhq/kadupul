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
 * The Data Template form saves an empty name, minimum or maximum when the
 * item's per-Data Source override is set, and export writes it. Import must
 * accept those templates. xml_to_data_template() runs in preview mode, which
 * reaches the item check without a database.
 */

namespace Kadupul\Tests\ImportDataSourceItemOverride;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

const POLLER_VERBOSITY_LOW = 1;

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = 0) {
	$GLOBALS['import_item_logs'][] = $message;
}

function db_fetch_cell_prepared($sql, $params = array()) {
	return '';
}

function db_fetch_cell($sql) {
	return '';
}

function db_fetch_row_prepared($sql, $params = array()) {
	return array();
}

function compare_data($save, $previous, $table) {
	return 0;
}

function resolve_hash_to_id($hash, $cache, $table) {
	return 1;
}

function parse_xml_hash($hash) {
	return preg_match('/hash_([a-f0-9]{2})([a-f0-9]{4})([a-f0-9]{32})/', $hash, $m) ? array('type' => 'data_template_item', 'version' => '1', 'hash' => $m[3]) : false;
}

$importSource    = file_get_contents(dirname(__DIR__, 2) . '/lib/import.php');
$functionsSource = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');

if (!is_string($importSource) || !is_string($functionsSource)) {
	throw new \RuntimeException('Unable to read the production sources.');
}

foreach (array(array($importSource, 'xml_to_data_template'), array($importSource, 'import_validate_data_source_item'), array($importSource, 'xml_character_decode'), array($functionsSource, 'clean_up_lines')) as $entry) {
	if (!function_exists(__NAMESPACE__ . '\\' . $entry[1])) {
		eval('namespace ' . __NAMESPACE__ . '; ' . test_php_function_source($entry[0], $entry[1])); // nosemgrep: php.lang.security.eval-use.eval-use
	}
}

function importItem(array $item) {
	$GLOBALS['preview_only']            = true;
	$GLOBALS['import_debug_info']       = array();
	$GLOBALS['import_messages']         = array();
	$GLOBALS['ignorable_hashes']        = array();
	$GLOBALS['legacy_template']         = false;
	$GLOBALS['import_template_id']      = 0;
	$GLOBALS['import_item_logs']        = array();
	$GLOBALS['struct_data_source']      = array();
	$GLOBALS['struct_data_source_item'] = array('data_source_name' => array(), 'rrd_minimum' => array(), 'rrd_maximum' => array());

	$xml   = array('name' => 'Template', 'ds' => array(), 'items' => array('hash_080001fedcba9876543210fedcba9876543210' => $item), 'data' => array());
	$cache = array();

	$result = xml_to_data_template('0123456789abcdef0123456789abcdef', $xml, $cache, true, 1);

	return array($result, $GLOBALS['import_messages'], $GLOBALS['import_item_logs']);
}

test('an empty field whose per-data-source override is set imports as exported', function ($field) {
	$item = array('data_source_name' => 'traffic_in', 'rrd_minimum' => '0', 'rrd_maximum' => 'U');
	$item[$field]        = '';
	$item['t_' . $field] = 'on';

	list($result, $messages, $logs) = importItem($item);

	expect($result)->toBeArray()->and($messages)->toBe(array())->and($logs)->toBe(array());
})->with(array('data_source_name', 'rrd_minimum', 'rrd_maximum'));

test('an empty field without its per-data-source override is still rejected', function ($field, $override) {
	$item = array('data_source_name' => 'traffic_in', 'rrd_minimum' => '0', 'rrd_maximum' => 'U');
	$item[$field]        = '';
	$item['t_' . $field] = $override;

	list($result, $messages, $logs) = importItem($item);

	expect($result)->toBeFalse()->and($messages)->toBe(array(45))
		->and($logs)->toHaveCount(1)->and($logs[0])->toContain("'" . $field . "'");
})->with(array(
	'override cleared'         => array('rrd_maximum', ''),
	'override other value'     => array('data_source_name', 'off'),
	'minimum override cleared' => array('rrd_minimum', ''),
));

test('a set override does not admit a non-empty invalid value', function () {
	list($result, $messages) = importItem(array('data_source_name' => 'in', 'rrd_minimum' => '0', 'rrd_maximum' => '1 2', 't_rrd_maximum' => 'on'));

	expect($result)->toBeFalse()->and($messages)->toBe(array(45));
});

test('ordinary values still import', function () {
	list($result, $messages) = importItem(array('data_source_name' => 'traffic_in', 'rrd_minimum' => '0', 'rrd_maximum' => '|query_ifSpeed|'));

	expect($result)->toBeArray()->and($messages)->toBe(array());
});
