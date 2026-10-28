<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * A graph template input copies one graph item field between graphs. The
 * column is already allowlisted; these tests cover the values that cross the
 * handoffs: a graph save, the push-out to existing graphs, and the item
 * references in an imported template. Production code runs in this namespace
 * with database and request doubles.
 */

namespace GraphTemplateInputValueTest;

require_once __DIR__ . '/../../Helpers/PhpSource.php';

$root = dirname(__DIR__, 3);
$functions = array(
    'lib/graph_template_input.php' => array('graph_template_input_column', 'graph_template_input_value_is_allowed', 'graph_template_input_storage_value', 'graph_template_input_xml_items_are_valid'),
    'lib/template.php' => array('push_out_graph_input'),
    'lib/import.php' => array('parse_xml_hash', 'check_hash_type', 'check_hash_version', 'xml_character_decode', 'xml_to_graph_template'),
    'lib/functions.php' => array('cacti_version_compare', 'version_to_decimal'),
);
foreach ($functions as $file => $names) {
    $source = file_get_contents($root . '/' . $file);
    foreach ($names as $name) {
        try {
            $code = test_php_function_source($source, $name);
        } catch (\RuntimeException $e) {
            // A helper that does not exist yet leaves the unguarded behaviour observable.
            continue;
        }
        eval('namespace ' . __NAMESPACE__ . '; ' . $code); // nosemgrep: php.lang.security.eval-use.eval-use
    }
}

const CACTI_VERSION = '1.2.31';
const POLLER_VERBOSITY_LOW = 2;
const MESSAGE_LEVEL_ERROR = 3;

final class Fixture
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public static array $writes = array();
    /** @var list<array<string, mixed>> */
    public static array $rows = array();
    /** @var array<string, mixed> */
    public static array $request = array();
    /** @var list<string> */
    public static array $log = array();
    public static string $column = 'alpha';
}

function db_fetch_row_prepared($sql, $params = array()): array
{
    return array('graph_template_id' => 2, 'column_name' => Fixture::$column);
}
function db_fetch_assoc_prepared($sql, $params = array()): array
{
    if (str_contains($sql, 'FROM graph_template_input_defs') && !str_contains($sql, 'INNER JOIN')) {
        return array(array('graph_template_item_id' => 30));
    }

    return Fixture::$rows;
}
function db_fetch_cell_prepared($sql, $params = array()): mixed
{
    throw new \RuntimeException('PERSISTENCE REACHED');
}
function db_execute_prepared($sql, $params = array()): bool
{
    Fixture::$writes[] = array(trim(preg_replace('/\s+/', ' ', $sql)), $params);

    return true;
}
function array_to_sql_or($items, $column): string
{
    return '(' . $column . ' IN (' . implode(',', array_map('intval', $items)) . '))';
}
function cacti_sizeof($value): int
{
    return is_array($value) ? count($value) : 0;
}
function cacti_count($value): int
{
    return count($value);
}
function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = 0): void
{
    Fixture::$log[] = $message;
}
function raise_message($id, $message = '', $level = 0): void
{
    Fixture::$log[] = $message;
}
function __($text, ...$arguments): string
{
    return $text;
}
function isset_request_var($name): bool
{
    return array_key_exists($name, Fixture::$request);
}
function get_nfilter_request_var($name): mixed
{
    return Fixture::$request[$name] ?? '';
}
function get_filter_request_var($name): mixed
{
    return Fixture::$request[$name] ?? '';
}

$savedGraphInputGlobals = array();

beforeEach(function () use (&$savedGraphInputGlobals) {
    foreach (array('hash_type_codes', 'cacti_version_codes') as $name) {
        $savedGraphInputGlobals[$name] = array_key_exists($name, $GLOBALS) ? array($GLOBALS[$name]) : null;
    }
    Fixture::$writes = array();
    Fixture::$rows = array();
    Fixture::$request = array();
    Fixture::$log = array();
    Fixture::$column = 'alpha';
    $GLOBALS['hash_type_codes'] = array('graph_template' => '00', 'graph_template_item' => '10', 'graph_template_input' => '09');
    $GLOBALS['cacti_version_codes'] = array('1.2.31' => '0103');
});

afterEach(function () use (&$savedGraphInputGlobals) {
    foreach ($savedGraphInputGlobals as $name => $value) {
        if ($value === null) {
            unset($GLOBALS[$name]);
        } else {
            $GLOBALS[$name] = $value[0];
        }
    }
});

/** Run the graphs.php block that applies submitted graph input values to a graph's items. */
function save_graph_inputs(array $request, ?array $inputs = null): array
{
    Fixture::$request = $request + array('local_graph_id' => '9', 'save_component_input' => '1');
    Fixture::$rows = array(array('id' => 101), array('id' => 102));
    $input_template_id = 2;
    $input_list = $inputs ?? array(array('id' => 7, 'column_name' => 'alpha'), array('id' => 8, 'column_name' => 'line_width'));
    $source = file_get_contents(dirname(__DIR__, 3) . '/graphs.php');
    eval('namespace ' . __NAMESPACE__ . '; if (' . test_php_block_source($source, "isset_request_var('save_component_input'))", '$input_template_id = 0;') . ';'); // nosemgrep: php.lang.security.eval-use.eval-use

    return Fixture::$writes;
}

function template_xml(string $item_reference): array
{
    $item = 'hash_100103' . str_repeat('a', 32);

    return array(
        'name' => 'template',
        'items' => array($item => array()),
        'inputs' => array('hash_090103' . str_repeat('c', 32) => array('column_name' => 'alpha', 'items' => $item_reference)),
    );
}

test('graph input values must have the shape of their graph item field', function (string $column, mixed $value, bool $allowed) {
    expect(graph_template_input_value_is_allowed($column, $value))->toBe($allowed);
})->with(array(
    array('alpha', 'FF', true),
    array('alpha', "FF' OR 1=1", false),
    array('color_id', '16777215', true),
    array('color_id', '16777216', false),
    array('cdef_id', '12', true),
    array('cdef_id', '1 OR 1', false),
    array('line_width', '1.5', true),
    array('line_width', '1;2', false),
    array('dashes', '5,10', true),
    array('dashes', '5:CDEF', false),
    array('dash_offset', '-8388608', true),
    array('dash_offset', '8388608', false),
    array('textalign', 'center', true),
    array('textalign', 'left:x', false),
    array('text_format', "Total\0Bytes", false),
    array('text_format', 'Inbound |host_description|', true),
    array('local_graph_id', '1', false),
    array('alpha', array('FF'), false),
    array('graph_type_id', '255', true),
    array('graph_type_id', '256', false),
    array('consolidation_function_id', '255', true),
    array('consolidation_function_id', '256', false),
    array('task_item_id', '4294967295', true),
    array('task_item_id', '4294967296', false),
    array('cdef_id', '16777215', true),
    array('cdef_id', '16777216', false),
    array('cdef_id', '0000000012', true),
    array('vdef_id', '16777215', true),
    array('vdef_id', '16777216', false),
    array('gprint_id', '16777215', true),
    array('gprint_id', '16777216', false),
    array('line_width', '99.99', true),
    array('line_width', '100', false),
    array('line_width', '999.9', false),
    array('line_width', '1.234', false),
    array('line_width', '1,5', true),
    array('line_width', '99,99', true),
    array('line_width', '100,0', false),
    array('line_width', '1,234', false),
    array('line_width', '01.00', true),
    array('dashes', str_repeat('1', 20), true),
    array('dashes', str_repeat('1', 21), false),
    array('textalign', str_repeat('a', 10), true),
    array('textalign', str_repeat('a', 11), false),
    array('text_format', str_repeat('界', 255), true),
    array('text_format', str_repeat('界', 256), false),
    array('text_format', str_repeat("\u{1f600}", 255), true),
    array('text_format', "line one\nline two", true),
    array('value', str_repeat('界', 255), true),
    array('value', str_repeat('界', 256), false),
    array('value', "\xff", false),
));

test('a later out-of-range graph input prevents every input write', function () {
    expect(save_graph_inputs(array('alpha_7' => 'FF', 'line_width_8' => '999.9')))->toBe(array());
});

test('graph saves preserve localized widths as decimal storage values', function () {
    $writes = save_graph_inputs(array('alpha_7' => 'FF', 'line_width_8' => '1,25'));
    expect(array_column($writes, 1))->toBe(array(array('FF', 101), array('FF', 102), array('1.25', 101), array('1.25', 102)));
});

test('an empty optional numeric graph input retains zero storage semantics', function (string $column) {
    $writes = save_graph_inputs(array($column . '_7' => ''), array(array('id' => 7, 'column_name' => $column)));
    expect(array_column($writes, 1))->toBe(array(array('0', 101), array('0', 102)));
})->with(array('color_id', 'line_width', 'dash_offset', 'sequence'));

test('an omitted graph input retains its existing value', function () {
    $writes = save_graph_inputs(array('alpha_7' => 'FF'));
    expect(array_column($writes, 1))->toBe(array(array('FF', 101), array('FF', 102)));
});

test('a valid multibyte graph label is preserved at the input handoff', function () {
    $label = str_repeat('界', 100);
    $writes = save_graph_inputs(array('text_format_7' => $label), array(array('id' => 7, 'column_name' => 'text_format')));
    expect(array_column($writes, 1))->toBe(array(array($label, 101), array($label, 102)));
});

test('push-out preserves valid multibyte labels and NULL values', function () {
    $label = str_repeat('界', 100);
    Fixture::$column = 'text_format';
    Fixture::$rows = array(array('local_graph_id' => 9, 'text_format' => $label), array('local_graph_id' => 10, 'text_format' => null));
    push_out_graph_input(5, 30, array());
    expect(array_column(Fixture::$writes, 1))->toBe(array(array($label, 9, 30), array(null, 10, 30)));
});

test('a graph save writes no graph input value when one is malformed', function () {
    expect(save_graph_inputs(array('alpha_7' => "FF' OR 1=1", 'line_width_8' => '2')))->toBe(array())
        ->and(implode("\n", Fixture::$log))->toContain('invalid value');
});

test('a graph save still applies valid graph input values', function () {
    $writes = save_graph_inputs(array('alpha_7' => 'FF', 'line_width_8' => '2'));

    expect(array_column($writes, 1))->toBe(array(array('FF', 101), array('FF', 102), array('2', 101), array('2', 102)));
});

test('push-out refuses malformed stored values before updating any graph', function () {
    Fixture::$rows = array(array('local_graph_id' => 9, 'alpha' => 'FF'), array('local_graph_id' => 10, 'alpha' => "\x01Z"));

    expect(push_out_graph_input(5, 30, array()))->toBeFalse()
        ->and(Fixture::$writes)->toBe(array());
});

test('push-out still copies valid and NULL stored values', function () {
    Fixture::$rows = array(array('local_graph_id' => 9, 'alpha' => 'FF'), array('local_graph_id' => 10, 'alpha' => null));
    push_out_graph_input(5, 30, array());

    expect(array_column(Fixture::$writes, 1))->toBe(array(array('FF', 9, 30), array(null, 10, 30)));
});

test('an imported graph input may only name items of its own template', function (string $reference) {
    $xml = template_xml($reference);
    $hash_cache = array();

    expect(xml_to_graph_template(str_repeat('b', 32), $xml, $hash_cache, '0103'))->toBeFalse()
        ->and(Fixture::$writes)->toBe(array());
})->with(array(
    'another template item' => array('hash_100103' . str_repeat('d', 32)),
    'malformed hash' => array('hash_10zz'),
    'mixed list' => array('hash_100103' . str_repeat('a', 32) . '|hash_100103' . str_repeat('d', 32)),
));

test('an imported graph input naming its own items reaches persistence', function () {
    $xml = template_xml('hash_100103' . str_repeat('a', 32));
    $hash_cache = array();

    expect(fn() => xml_to_graph_template(str_repeat('b', 32), $xml, $hash_cache, '0103'))->toThrow(\RuntimeException::class, 'PERSISTENCE REACHED');
});
