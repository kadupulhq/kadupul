<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Names stored with automation data become SQL identifiers or string literals
 * in the queries lib/api_automation.php builds. Backticks quote an identifier,
 * they do not escape one, so a name holding a backtick or a quote must not be
 * able to end the quoting. Upstream Cacti #7701 and the automation pivot part
 * of #8149. The production functions run in a child process.
 */

namespace Kadupul\Tests\Security\AutomationSqlIdentifier;

/** @return array<string, mixed> */
function runAutomation(array $scenario): array
{
    $root = dirname(__DIR__, 3);
    $program = <<<'CHILD'
        $root = $argv[1];
        $scenario = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
        $config = array('base_path' => $root);
        require $root . '/include/global_constants.php';
        require $root . '/tests/Helpers/PhpSource.php';
        $source = file_get_contents($root . '/lib/functions.php');
        foreach (array('sanitize_sql_column', 'array_rekey') as $function) {
            eval(test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
        }
        function __($text, ...$arguments) { return $text; }
        function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
        function cacti_log(...$arguments) { }
        function db_qstr($value) { return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $value) . "'"; }
        function get_best_data_query_index_type($host_id, $snmp_query_id) { return 'ifIndex'; }
        function db_fetch_assoc($sql) {
            if (str_contains($sql, 'SELECT DISTINCT field_name FROM host_snmp_cache WHERE snmp_query_id=')) {
                return array_map(static fn($name) => array('field_name' => $name), $GLOBALS['scenario']['fields']);
            }
            $GLOBALS['queries'][] = $sql;
            return array();
        }
        function db_fetch_cell_prepared($sql, $params = array()) { return 'rule'; }
        function db_fetch_assoc_prepared($sql, $params = array()) {
            if (str_contains($sql, 'SELECT DISTINCT field_name')) {
                return array_map(static fn($name) => array('field_name' => $name), $GLOBALS['scenario']['fields']);
            }
            if (str_contains($sql, 'ORDER BY sequence')) {
                return $GLOBALS['scenario']['rule_items'];
            }
            return array_map(static fn($name) => array('field' => $name), $GLOBALS['scenario']['rule_fields']);
        }
        $automation_oper = array(AUTOMATION_OPER_NULL => '', AUTOMATION_OPER_AND => 'AND', AUTOMATION_OPER_OR => 'OR',
            AUTOMATION_OPER_LEFT_BRACKET => '(', AUTOMATION_OPER_RIGHT_BRACKET => ')');
        $automation_op_array = array(
            'op' => array(AUTOMATION_OP_CONTAINS => 'LIKE', AUTOMATION_OP_MATCHES => '='),
            'binary' => array(AUTOMATION_OP_CONTAINS => true, AUTOMATION_OP_MATCHES => true),
            'pre' => array(AUTOMATION_OP_CONTAINS => '%', AUTOMATION_OP_MATCHES => ''),
            'post' => array(AUTOMATION_OP_CONTAINS => '%', AUTOMATION_OP_MATCHES => ''),
        );
        require $root . '/lib/api_automation.php';
        $GLOBALS['scenario'] = $scenario;
        $GLOBALS['queries'] = array();
        $items = array_map(static fn($field) => array('operation' => AUTOMATION_OPER_NULL, 'field' => $field,
            'operator' => AUTOMATION_OP_CONTAINS, 'pattern' => 'x'), $scenario['items_fields']);
        $GLOBALS['scenario']['rule_items'] = $items;
        $GLOBALS['scenario']['rule_fields'] = $scenario['fields'];
        $out = array(
            'filter' => build_rule_item_filter($items, ' h.'),
            'having' => build_graph_object_sql_having(array('snmp_query_id' => 7), 'needle'),
            'data_query' => build_data_query_sql(array('id' => 3, 'snmp_query_id' => 7)),
        );
        $rule = array('id' => 3, 'name' => 'rule', 'snmp_query_id' => 7, 'graph_type_id' => 9);
        create_dq_graphs(5, 7, $rule);
        $out['pivot'] = $GLOBALS['queries'];
        print json_encode($out, JSON_THROW_ON_ERROR);
        CHILD;
    $process = proc_open(
        array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'memory_limit=512M', '-r', $program, $root, json_encode($scenario, JSON_THROW_ON_ERROR)),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    if (!is_resource($process)) {
        throw new \RuntimeException('Unable to start the automation child process.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    expect($stderr)->toBe('')->and($status)->toBe(0);

    return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
}

test('an ordinary stored field name keeps its quoted dotted identifier', function () {
    $out = runAutomation(array('fields' => array('ifDescr'), 'items_fields' => array('hostname')));

    expect($out['filter'])->toBe('   h.`hostname` LIKE \'%x%\'')
        ->and($out['having'])->toBe(' HAVING (`ifDescr` LIKE \'%needle%\')');
});

test('a backtick in a rule item field cannot close the identifier quoting', function () {
    $out = runAutomation(array('fields' => array('ifDescr'), 'items_fields' => array('hostname` OR 1=1 -- ')));

    expect($out['filter'])->not->toContain(' OR 1=1')
        ->and($out['filter'])->toContain('`hostnameOR11`');
});

test('a rule item field with an empty dotted part fails closed instead of rendering an empty identifier', function () {
    $out = runAutomation(array('fields' => array('ifDescr'), 'items_fields' => array('h.', '`')));

    expect($out['filter'])->not->toContain('``')
        ->and($out['filter'])->toBe('  1 = 0 1 = 0');
});

test('the having filter confines each stored field name and drops unusable ones', function () {
    $out = runAutomation(array('fields' => array('if`Descr', '`', 'ok.name'), 'items_fields' => array()));

    expect($out['having'])->toBe(' HAVING (`ifDescr` LIKE \'%needle%\' OR `ok`.`name` LIKE \'%needle%\')');
});

test('a having filter whose fields are all unusable matches nothing', function () {
    $out = runAutomation(array('fields' => array('`', '..'), 'items_fields' => array()));

    expect($out['having'])->toBe(' HAVING (1 = 0)');
});

test('data query pivot columns quote a stored field name as a literal', function () {
    $name = "x' THEN 1 ELSE 2 END) AS y, (SELECT 1) AS '";
    $out = runAutomation(array('fields' => array($name), 'items_fields' => array()));
    $quoted = "'x\\' THEN 1 ELSE 2 END) AS y, (SELECT 1) AS \\''";

    expect($out['data_query'])->toContain('MAX(CASE WHEN field_name = ' . $quoted . ' THEN field_value ELSE NULL END) AS ' . $quoted)
        ->and($out['pivot'][0])->toContain('MAX(CASE WHEN field_name = ' . $quoted . ' THEN field_value ELSE NULL END) AS ' . $quoted);
});
