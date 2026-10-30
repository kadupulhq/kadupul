<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * data_source_name, rrd_minimum and rrd_maximum reach an RRDtool command line
 * but a template XML never passes through the data_sources.php form. The import
 * applies the same field rules at the XML handoff. Upstream Cacti #7720.
 * xml_to_data_template() runs in preview mode, which reaches the item loop
 * without a database; the loop is shared with the write path.
 */

namespace Kadupul\Tests\Security\ImportDataSourceItemValidation;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

const POLLER_VERBOSITY_LOW = 1;

function cacti_log(string $message, bool $output = false, string $environ = 'CMDPHP', int $level = 0): void
{
    $GLOBALS['import_item_logs'][] = $message;
}

function db_fetch_cell_prepared(string $sql, array $params = []): mixed
{
    return '';
}

function db_fetch_cell(string $sql): mixed
{
    return '';
}

function db_fetch_row_prepared(string $sql, array $params = []): array
{
    return [];
}

function compare_data(array $save, array $previous, string $table): int
{
    return 0;
}

function resolve_hash_to_id(string $hash, array $cache, string $table): int
{
    return 1;
}

function parse_xml_hash(string $hash): array|false
{
    return preg_match('/hash_([a-f0-9]{2})([a-f0-9]{4})([a-f0-9]{32})/', $hash, $m) ? ['type' => 'data_template_item', 'version' => '1', 'hash' => $m[3]] : false;
}

$importSource = file_get_contents(dirname(__DIR__, 3) . '/lib/import.php');
$functionsSource = file_get_contents(dirname(__DIR__, 3) . '/lib/functions.php');
if (!is_string($importSource) || !is_string($functionsSource)) {
    throw new \RuntimeException('Unable to read the production sources.');
}

foreach ([[$importSource, 'xml_to_data_template'], [$importSource, 'import_validate_data_source_item'], [$importSource, 'import_invalid_data_source_item'], [$importSource, 'xml_character_decode'], [$functionsSource, 'clean_up_lines']] as [$source, $function]) {
    if (!function_exists(__NAMESPACE__ . '\\' . $function)) {
        eval('namespace ' . __NAMESPACE__ . '; ' . test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
}

/** @return array{0: mixed, 1: array|null, 2: list<string>} */
function importItem(array $item): array
{
    $GLOBALS['preview_only'] = true;
    $GLOBALS['import_debug_info'] = [];
    $GLOBALS['ignorable_hashes'] = [];
    $GLOBALS['legacy_template'] = false;
    $GLOBALS['import_template_id'] = 0;
    $GLOBALS['import_item_logs'] = [];
    $GLOBALS['struct_data_source'] = [];
    $GLOBALS['struct_data_source_item'] = ['data_source_name' => [], 'rrd_minimum' => [], 'rrd_maximum' => []];
    $xml = ['name' => 'Template', 'ds' => [], 'items' => ['hash_080001fedcba9876543210fedcba9876543210' => $item], 'data' => []];
    $cache = [];

    $result = xml_to_data_template('0123456789abcdef0123456789abcdef', $xml, $cache, true, 1);

    return [$result, import_invalid_data_source_item($xml), $GLOBALS['import_item_logs']];
}

test('ordinary data source item values are accepted', function (string $name, string $minimum, string $maximum) {
    [$result, $invalid] = importItem(['data_source_name' => $name, 'rrd_minimum' => $minimum, 'rrd_maximum' => $maximum]);

    expect($result)->toBeArray()->and($invalid)->toBeNull();
})->with([
    'counter' => ['traffic_in', '0', 'U'],
    'speed variable' => ['traffic-out', '0', '|query_ifSpeed|'],
    'high speed variable' => ['hc_in', 'U', '|query_ifHighSpeed|'],
    'decimal and exponent' => ['load_1min', '-1.5', '1e3'],
    'longest name' => ['abcdefghijklmnopqrs', '0', '100'],
]);

test('a rejected item field stops the template import and names the field in the log', function (array $item, string $field) {
    [$result, $invalid, $logs] = importItem($item);

    expect($result)->toBeFalse()->and($invalid[0])->toBe($field);
    expect($logs)->toHaveCount(1)->and($logs[0])->toContain("'" . $field . "'");
})->with([
    'name with a quote' => [['data_source_name' => "in' --", 'rrd_minimum' => '0', 'rrd_maximum' => 'U'], 'data_source_name'],
    'name over 19 characters' => [['data_source_name' => 'abcdefghijklmnopqrst', 'rrd_minimum' => '0', 'rrd_maximum' => 'U'], 'data_source_name'],
    'name with a trailing newline' => [['data_source_name' => "in\n", 'rrd_minimum' => '0', 'rrd_maximum' => 'U'], 'data_source_name'],
    'minimum with a command separator' => [['data_source_name' => 'in', 'rrd_minimum' => '0:GPRINT', 'rrd_maximum' => 'U'], 'rrd_minimum'],
    'maximum with a space' => [['data_source_name' => 'in', 'rrd_minimum' => '0', 'rrd_maximum' => '1 2'], 'rrd_maximum'],
]);

test('a rejected value cannot add lines to the log', function () {
    [, , $logs] = importItem(['data_source_name' => "ok\nFATAL: forged", 'rrd_minimum' => '0', 'rrd_maximum' => 'U']);

    expect($logs)->toHaveCount(1)->and($logs[0])->not->toContain("\n");
});

test('the importer reports the rejection with a message the import page can show', function () {
    $import = file_get_contents(dirname(__DIR__, 3) . '/lib/import.php');
    expect($import)->toContain("if (import_invalid_data_source_item(\$hash_array) !== null) {\n                                \$import_messages[] = 45;");

    $arrays = file_get_contents(dirname(__DIR__, 3) . '/include/global_arrays.php');

    expect($arrays)->toContain("    45 => array(\n        'message' => __('Data Source Item field holds a value the Data Source form would reject.'),");
});
