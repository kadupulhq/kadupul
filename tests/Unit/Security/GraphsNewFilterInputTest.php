<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\Security\GraphsNewFilterInput;

use RuntimeException;

/*
 * graphs_new.php builds an snmp_index IN() list from stored host_snmp_cache
 * rows, and graphs.php validates its template filter with a pattern. Upstream
 * Cacti #8138 (GHSA-j3px-vw6r-g25x) and #7866.
 */

$root = dirname(__DIR__, 3);

require_once $root . '/tests/Helpers/PhpSource.php';

foreach (array('MESSAGE_LEVEL_ERROR' => 3, 'FILTER_VALIDATE_IS_NUMERIC_LIST' => 1048576, 'FILTER_VALIDATE_IS_NUMERIC_ARRAY' => 1048577, 'FILTER_VALIDATE_IS_REGEX' => 1048578, 'MAX_DISPLAY_PAGES' => 5) as $constant => $value) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

function loadProduction(string $root, string $file, array $functions): void
{
    $source = file_get_contents($root . '/' . $file);
    if ($source === false) {
        throw new RuntimeException('Unreadable ' . $file);
    }
    foreach ($functions as $function) {
        // Test-only: loads this repository's own function source into a private namespace.
        eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source($source, $function));
    }
}

loadProduction($root, 'lib/functions.php', array('array_rekey'));
loadProduction($root, 'graphs_new.php', array('graphs'));
loadProduction($root, 'graphs.php', array('validate_graph_request_vars'));

// Test-only: the shipped class is a static helper; only its attribute output matters here.
eval('namespace ' . __NAMESPACE__ . '; class CactiSecureHeaders { public static function getNonceAttribute() { return ""; } }');

// Every other function graphs() calls renders markup or reads session state that this check does not need.
$source = file_get_contents($root . '/graphs_new.php');
preg_match_all('/\b([a-z_][a-z_0-9]*)\s*\(/i', \test_php_function_source($source, 'graphs'), $calls);
foreach (array_unique($calls[1]) as $name) {
    if (!function_exists($name) && !function_exists(__NAMESPACE__ . '\\' . $name) && !in_array(strtolower($name), array('function', 'array', 'isset', 'empty', 'unset', 'list', 'if', 'foreach', 'while', 'switch', 'elseif', 'print', 'echo', 'for'), true)) {
        // Test-only stub for a rendering or session helper.
        eval('namespace ' . __NAMESPACE__ . '; function ' . $name . '(...$arguments) { return null; }');
    }
}

/** Records the filter definitions that the shipped functions pass on. */
function validate_store_request_vars($filters, $session)
{
    $GLOBALS['capturedFilters'] = $filters;
}

function record(string $kind, string $sql, array $params = array()): array
{
    $GLOBALS['sqlLog'][] = array('kind' => $kind, 'sql' => $sql, 'params' => $params);
    foreach ($GLOBALS['sqlRows'] as $pattern => $rows) {
        if (preg_match($pattern, $sql)) {
            return $rows;
        }
    }

    return array();
}

function db_qstr($value)
{
    return "'" . addslashes((string) $value) . "'";
}

function db_fetch_assoc($sql)
{
    return record('fetch', $sql);
}

function db_fetch_assoc_prepared($sql, $params = array())
{
    return record('fetch', $sql, $params);
}

function db_fetch_row_prepared($sql, $params = array())
{
    $rows = record('row', $sql, $params);

    return $rows[0] ?? array();
}

function db_fetch_cell_prepared($sql, $params = array())
{
    $rows = record('cell', $sql, $params);

    return $rows[0] ?? false;
}

function cacti_sizeof($array)
{
    return is_array($array) ? count($array) : 0;
}

function get_request_var($name, $default = '')
{
    return $_REQUEST[$name] ?? $default;
}

function get_nfilter_request_var($name, $default = '')
{
    return $_REQUEST[$name] ?? $default;
}

function isempty_request_var($name)
{
    return empty($_REQUEST[$name]);
}

function isset_request_var($name)
{
    return isset($_REQUEST[$name]);
}

function set_request_var($name, $value)
{
    $_REQUEST[$name] = $value;
}

function graphs_new_default_host_id()
{
    return 0;
}

function graphs_new_host_is_allowed($host_id, $requires_device = false)
{
    return true;
}

function get_data_query_array($id)
{
    return array('index_order_type' => 'numeric', 'fields' => array('ifDescr' => array('direction' => 'input', 'name' => 'Description')));
}

function html_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

function __($text, ...$args)
{
    return vsprintf($text, $args);
}

function __esc($text, ...$args)
{
    return htmlspecialchars(vsprintf($text, $args), ENT_QUOTES);
}

beforeEach(function () {
    $GLOBALS['sqlLog'] = $GLOBALS['sqlRows'] = array();
    $GLOBALS['config'] = array();
    $GLOBALS['item_rows'] = array(10 => '10');
});

test('the data query pivot quotes each stored snmp index', function (array $indexes, string $expected) {
    $_REQUEST = array('host_id' => 7, 'graph_type' => -2, 'filter' => 'eth', 'rows' => 10, 'page3' => 1);
    $GLOBALS['sqlRows'] = array(
        '/FROM host\s+WHERE id/' => array(array('id' => 7, 'description' => 'edge', 'hostname' => 'edge.example', 'host_template_id' => 0)),
        '/FROM snmp_query AS sq/' => array(array('id' => 3, 'name' => 'Interfaces')),
        '/SELECT DISTINCT snmp_index\s+FROM host_snmp_cache/' => array_map(fn($index) => array('snmp_index' => $index), $indexes),
    );

    ob_start();
    graphs();
    ob_end_clean();

    $pivots = array_values(array_filter($GLOBALS['sqlLog'], fn($entry) => $entry['kind'] === 'fetch' && str_contains($entry['sql'], 'GROUP BY host_id, snmp_query_id, snmp_index')));

    expect($pivots)->not->toBeEmpty();
    foreach ($pivots as $pivot) {
        expect($pivot['sql'])->toContain($expected);
    }
})->with(array(
    'plain indexes' => array(array('1', '12'), "AND snmp_index IN('1', '12')"),
    'quote in an index' => array(array("9' OR '1'='1"), "AND snmp_index IN('9\\' OR \\'1\\'=\\'1')"),
    'backslash in an index' => array(array('a\\', 'b'), "AND snmp_index IN('a\\\\', 'b')"),
));

test('the graph template filter accepts only a whole template reference', function (string $value, bool $valid) {
    validate_graph_request_vars();

    $filter = $GLOBALS['capturedFilters']['template_id'];

    expect(filter_var($value, $filter['filter'], $filter['options']) !== false)->toBe($valid);
})->with(array(
    'all templates' => array('-1', true),
    'no template' => array('0', true),
    'template id' => array('12', true),
    'graph template' => array('cg_5', true),
    'data query graph' => array('dq_17', true),
    'trailing text' => array('5 OR 1=1', false),
    'leading text' => array('x-1', false),
    'graph template with text' => array("cg_5'; DROP TABLE host", false),
    'embedded digit' => array('abc1', false),
    'empty' => array('', false),
));
