<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace {

if (PHP_SAPI !== 'cli' || $argc !== 2) {
	exit(64);
}

function read_config_option(string $name): string {
	return '';
}
}

namespace BinaryGraphDivisorProbe {

$root = dirname(__DIR__, 2);
require $root . '/tests/Helpers/PhpSource.php';
$config = array('library_path' => $root . '/lib');
$case = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$fetches = array();

function rrdtool_function_contains_cf(int $id, string $cf): bool {
	return $cf === 'AVERAGE' || !empty($GLOBALS['case']['max']);
}

function rrdtool_function_fetch(int $id, int $start, int $end, int $resolution = 0, bool $unknown = false, ?string $file = null, string $cf = 'AVERAGE'): array {
	$GLOBALS['fetches'][] = array($id, $start, $end, $resolution, $cf);
	$value = $GLOBALS['case']['value'];
	return array('data_source_names' => array('traffic'), 'values' => array(array(60 => $value, 120 => $value, 180 => $value, 240 => $value)));
}

function number_format_i18n(float|int $number, int $precision = 0): string {
	return number_format($number, $precision, '.', '');
}

foreach (array('lib/functions.php' => array('cacti_sizeof', 'cacti_count'),
	'lib/graph_variables.php' => array('nth_percentile', 'rrdtool_function_stats', 'nth_percentile_fetch_statistics', 'cacti_percentile_index', 'cacti_stats_calc',
		'is_graphable_item', 'variable_nth_percentile', 'bandwidth_summation', 'variable_bandwidth_summation')) as $file => $names) {
	$source = file_get_contents($root . '/' . $file);
	if ($source === false) {
		throw new \RuntimeException('Cannot read production source');
	}
	foreach ($names as $name) {
		eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $name));
	}
}

$graph_item_types = array(1 => 'LINE1');
$graph = array('base_value' => $case['base']);
$item = array('local_data_id' => 1, 'data_source_name' => 'traffic', 'data_template_rrd_id' => 1, 'graph_type_id' => 1);
$items = array($item);
if ($case['mode'] === 'percentile') {
	$matches = array('', 95, $case['unit'] ?? 'bytes', $case['power'], $case['type'], 2);
	$result = variable_nth_percentile($matches, $graph, $item, $items, 0, 240);
} else {
	$matches = array('', $case['power'], $case['type'], 2, '');
	$result = variable_bandwidth_summation($matches, $graph, $item, $items, 0, 240, 1, $case['step'] ?? 1);
}
echo json_encode(array('result' => $result, 'fetches' => $fetches), JSON_THROW_ON_ERROR), PHP_EOL;
}
