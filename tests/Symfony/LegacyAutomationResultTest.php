<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\AutomationResults;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../Helpers/PhpSource.php';
$source = file_get_contents(__DIR__ . '/../../lib/api_automation.php');
foreach (['automation_update_device', 'automation_execute_graph_template', 'automation_execute_data_query', 'create_dq_graphs', 'automation_execute_device_create_tree', 'automation_execute_graph_create_tree', 'automation_graph_result_exists', 'automation_hook_graph_create_tree', 'create_all_header_nodes', 'create_multi_header_node'] as $function) {
    // Execute the production orchestration and result checks, not a void-method stub.
    eval('namespace ' . __NAMESPACE__ . '; use RuntimeException;' . \test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
}
const POLLER_VERBOSITY_MEDIUM = 1;
const POLLER_VERBOSITY_HIGH = 2;
const POLLER_VERBOSITY_DEBUG = 3;
const TREE_ITEM_TYPE_HOST = 1;
const TREE_ITEM_TYPE_GRAPH = 2;
const AUTOMATION_RULE_TYPE_GRAPH_MATCH = 1;
const AUTOMATION_RULE_TYPE_TREE_MATCH = 2;
const AUTOMATION_TREE_ITEM_TYPE_STRING = 'string';

final class RuleFixture
{
    public static PDO $db;
    public static string $kind;
    public static string $outcome;
}
function automation_function_with_pid($name)
{
    return $name;
}
function cacti_log(...$args) {}
function cacti_sizeof($rows)
{
    return is_array($rows) ? count($rows) : 0;
}
function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}
function getInputFields($id)
{
    return [];
}
function automation_graph_automation_eligible($id)
{
    return true;
}
function test_data_sources(...$args)
{
    return RuleFixture::$outcome !== 'invalid-data';
}
function push_out_host(...$args) {}
function read_config_option($key)
{
    return RuleFixture::$kind === 'graph-tree' ? 'on' : '';
}
function build_matching_objects_filter(...$args)
{
    return '1=1';
}
function build_rule_item_filter(...$args)
{
    return '';
}
function get_best_data_query_index_type(...$args)
{
    return 'ifIndex';
}
function get_matching_hosts(...$args)
{
    return [['host_id' => 7]];
}
function get_matching_graphs(...$args)
{
    return [['id' => 8]];
}
function create_header_node(...$args)
{
    return false;
}
function db_fetch_assoc($sql, ...$args)
{
    if (str_contains($sql, 'FROM graph_templates AS gt')) {
        return in_array(RuleFixture::$kind, ['graph', 'graph-tree'], true) ? [['id' => 5]] : [];
    }
    if (str_contains($sql, 'FROM automation_tree_rules')) {
        $graph = str_contains($sql, 'leaf_type=2');
        if (($graph && RuleFixture::$kind === 'graph-tree') || (!$graph && RuleFixture::$kind === 'tree')) {
            return [['id' => 1, 'name' => 'fixture', 'tree_id' => 3, 'tree_item_id' => 0, 'leaf_type' => $graph ? 2 : 1, 'host_grouping_type' => 1]];
        }
        return [];
    }
    if (str_contains($sql, 'FROM host AS h')) {
        return [['host_id' => 7]];
    }
    if (str_contains($sql, 'FROM (SELECT')) {
        return [['snmp_index' => 'eth0']];
    }
    throw new RuntimeException('Unexpected fixture query: ' . $sql);
}
function db_fetch_assoc_prepared($sql, $parameters)
{
    if (str_contains($sql, 'FROM snmp_query AS sq')) {
        return RuleFixture::$kind === 'query' ? [['id' => 4]] : [];
    }
    if (str_contains($sql, 'FROM automation_graph_rules AS agr')) {
        return [['id' => 1, 'name' => 'fixture', 'snmp_query_id' => 4, 'graph_type_id' => 2]];
    }
    if (str_contains($sql, 'FROM automation_tree_rule_items')) {
        return RuleFixture::$outcome === 'header-failure' ? [['field' => 'string', 'search_pattern' => 'fixture']] : [];
    }
    if (str_contains($sql, 'FROM automation_graph_rule_items') || str_contains($sql, 'FROM host_snmp_cache')) {
        return [];
    }
    throw new RuntimeException('Unexpected fixture prepared query: ' . $sql);
}
function db_fetch_cell_prepared($sql, $parameters)
{
    if (str_contains($sql, 'SELECT COUNT(*)')) {
        $query = RuleFixture::$db->prepare($sql);
        $query->execute($parameters);
        return $query->fetchColumn();
    }
    if (str_contains($sql, 'FROM graph_local')) {
        return 0;
    }
    if (str_contains($sql, 'FROM snmp_query_graph')) {
        return 5;
    }
    if (str_contains($sql, 'FROM automation_graph_rules')) {
        return 'fixture';
    }
    if (str_contains($sql, 'data_template_rrd.local_data_id')) {
        return 11;
    }
    throw new RuntimeException('Unexpected fixture scalar query: ' . $sql);
}
function create_complete_graph_from_template($template, $host, $query, &$suggested)
{
    if (RuleFixture::$outcome === 'false') {
        return false;
    }
    if (RuleFixture::$outcome === 'empty') {
        return [];
    }
    if (RuleFixture::$outcome !== 'unpersisted') {
        RuleFixture::$db->prepare('INSERT INTO graph_local VALUES (8,?,?,?,?,?)')->execute([RuleFixture::$outcome === 'wrong-owner' ? 99 : $host, $template, $query['snmp_query_id'] ?? 0, $query['snmp_query_graph_id'] ?? 0, $query['snmp_index'] ?? '']);
    }
    if (RuleFixture::$kind === 'graph-tree') {
        automation_hook_graph_create_tree(['id' => 8]);
    }
    if (RuleFixture::$outcome !== 'missing-data') {
        RuleFixture::$db->prepare('INSERT INTO data_local VALUES (11,?,?,?)')->execute([
            RuleFixture::$outcome === 'wrong-data-owner' ? 99 : $host,
            RuleFixture::$outcome === 'wrong-data-query' ? 999 : ($query['snmp_query_id'] ?? 0),
            RuleFixture::$outcome === 'wrong-data-index' ? 'other' : ($query['snmp_index'] ?? ''),
        ]);
    }
    $id = match (RuleFixture::$outcome) {
        'failed-data' => false, 'zero-data' => 0, 'negative-data' => -1, 'malformed-data' => '11oops', default => 11,
    };
    return ['local_graph_id' => 8, 'local_data_id' => [$id]];
}
function fixture_tree_node($host, $graph, $parent, $rule)
{
    if (RuleFixture::$outcome === 'false') {
        return false;
    }
    if (RuleFixture::$outcome !== 'unpersisted') {
        RuleFixture::$db->prepare('INSERT INTO graph_tree_items VALUES (9,?,?,?,?)')->execute([$rule['tree_id'], $parent, $host, $graph]);
    }
    return 9;
}
function create_device_node($host, $parent, $rule)
{
    return fixture_tree_node($host, 0, $parent, $rule);
}
function create_graph_node($graph, $parent, $rule)
{
    return fixture_tree_node(0, $graph, $parent, $rule);
}

final class LegacyAutomationResultTest extends TestCase
{
    private string $directory;
    private mixed $configuration;
    protected function setUp(): void
    {
        $this->configuration = $GLOBALS['config'] ?? null;
        $this->directory = sys_get_temp_dir() . '/automation-result-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/lib', 0700, true);
        foreach (['template.php', 'api_automation_tools.php', 'utility.php'] as $file) {
            file_put_contents($this->directory . '/lib/' . $file, '<?php');
        }
        $GLOBALS['config'] = ['base_path' => $this->directory];
        $GLOBALS['automation_tree_header_types'] = ['string' => 'fixture'];
        RuleFixture::$db = new PDO('sqlite::memory:');
        RuleFixture::$db->exec('CREATE TABLE data_local (id INTEGER, host_id INTEGER, snmp_query_id INTEGER, snmp_index TEXT)');
        RuleFixture::$db->exec('CREATE TABLE graph_local (id INTEGER, host_id INTEGER, graph_template_id INTEGER, snmp_query_id INTEGER, snmp_query_graph_id INTEGER, snmp_index TEXT); CREATE TABLE graph_tree_items (id INTEGER, graph_tree_id INTEGER, parent INTEGER, host_id INTEGER, local_graph_id INTEGER)');
    }
    protected function tearDown(): void
    {
        foreach (['template.php', 'api_automation_tools.php', 'utility.php'] as $file) {
            unlink($this->directory . '/lib/' . $file);
        }
        rmdir($this->directory . '/lib');
        rmdir($this->directory);
        $GLOBALS['config'] = $this->configuration;
        unset($GLOBALS['automation_tree_header_types']);
    }
    #[DataProvider('outcomes')]
    public function testProductionAutomationReportsOnlyVerifiedOutcomes(string $kind, string $outcome, bool $expected): void
    {
        RuleFixture::$kind = $kind;
        RuleFixture::$outcome = $outcome;
        self::assertSame($expected, automation_update_device(7));
    }
    public function testGraphTreeHookFailureCannotBeLostInThePluginDataContract(): void
    {
        RuleFixture::$kind = 'graph-tree';
        RuleFixture::$outcome = 'unpersisted';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Graph tree automation failed');
        automation_update_device(7);
    }
    public static function outcomes(): iterable
    {
        yield 'no applicable rules is complete' => ['none', 'valid', true];
        foreach (['graph', 'query'] as $kind) {
            foreach (['false', 'empty', 'unpersisted', 'wrong-owner', 'invalid-data', 'failed-data', 'zero-data', 'negative-data', 'malformed-data', 'missing-data', 'wrong-data-owner', 'valid'] as $outcome) {
                yield "$kind $outcome" => [$kind, $outcome, $outcome === 'valid'];
            }
        }
        yield 'query wrong data query' => ['query', 'wrong-data-query', false];
        yield 'query wrong data index' => ['query', 'wrong-data-index', false];
        foreach (['false', 'unpersisted', 'header-failure', 'valid'] as $outcome) {
            yield "tree $outcome" => ['tree', $outcome, $outcome === 'valid'];
        }
    }
}
