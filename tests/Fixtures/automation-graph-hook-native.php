<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\AutomationGraphHookNative;

use PDO;
use RuntimeException;

require_once __DIR__ . '/../Helpers/PhpSource.php';
$root = dirname(__DIR__, 2);
foreach (['lib/template.php' => ['create_complete_graph_from_template'], 'lib/api_automation.php' => ['automation_hook_graph_create_tree', 'automation_execute_graph_create_tree']] as $path => $functions) {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read production graph automation');
    }
    foreach ($functions as $function) {
        eval('namespace ' . __NAMESPACE__ . '; use RuntimeException; use Throwable;' . \test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
}

const POLLER_VERBOSITY_HIGH = 2;
const POLLER_VERBOSITY_DEBUG = 3;
const TREE_ITEM_TYPE_GRAPH = 2;
const AUTOMATION_RULE_TYPE_TREE_MATCH = 2;

$strict = ($argv[1] ?? '') === 'strict';
$outcome = $argv[2] ?? 'failure';
if ($strict) {
    define('KADUPUL_THROW_DATABASE_ERRORS', true);
} elseif (($argv[1] ?? '') === 'legacy-false-flag') {
    define('KADUPUL_THROW_DATABASE_ERRORS', false);
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// Focused relational fixture: production queries and writes below use the
// actual column names. Template cloning and external cache work are stubbed.
$db->exec(<<<'SQL'
CREATE TABLE graph_templates (id INTEGER PRIMARY KEY);
INSERT INTO graph_templates VALUES (5);
CREATE TABLE graph_local (id INTEGER PRIMARY KEY, graph_template_id INTEGER, host_id INTEGER, snmp_query_id INTEGER DEFAULT 0, snmp_query_graph_id INTEGER DEFAULT 0, snmp_index TEXT DEFAULT '');
CREATE TABLE data_local (id INTEGER PRIMARY KEY, data_template_id INTEGER, host_id INTEGER);
CREATE TABLE data_template (id INTEGER PRIMARY KEY, name TEXT);
INSERT INTO data_template VALUES (3, 'Fixture data');
CREATE TABLE data_template_rrd (id INTEGER PRIMARY KEY, data_template_id INTEGER, local_data_id INTEGER, local_data_template_rrd_id INTEGER, data_source_name TEXT);
INSERT INTO data_template_rrd VALUES (2, 3, 0, 0, 'value');
CREATE TABLE data_template_data (id INTEGER PRIMARY KEY, local_data_id INTEGER);
CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, graph_template_id INTEGER, local_graph_id INTEGER, local_graph_template_item_id INTEGER, task_item_id INTEGER);
INSERT INTO graph_templates_item VALUES (1, 5, 0, 0, 2);
CREATE TABLE automation_tree_rules (id INTEGER, name TEXT, tree_id INTEGER, tree_item_id INTEGER, leaf_type INTEGER, host_grouping_type INTEGER, enabled TEXT);
INSERT INTO automation_tree_rules VALUES (1, 'Fixture tree', 3, 0, 2, 1, 'on');
CREATE TABLE graph_tree_items (id INTEGER PRIMARY KEY, graph_tree_id INTEGER, parent INTEGER, local_graph_id INTEGER);
SQL);
$plugins = [];
$errors = [];
function db_fetch_assoc_prepared(string $sql, array $parameters): array
{
    global $db;
    $query = $db->prepare($sql);
    $query->execute($parameters);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_assoc(string $sql): array
{
    return db_fetch_assoc_prepared($sql, []);
}
function db_fetch_cell_prepared(string $sql, array $parameters): mixed
{
    global $db;
    $query = $db->prepare($sql);
    $query->execute($parameters);
    return $query->fetchColumn();
}
function db_execute_prepared(string $sql, array $parameters): bool
{
    global $db;
    return $db->prepare($sql)->execute($parameters);
}
function sql_save(array $row, string $table): int
{
    global $db;
    if (!in_array($table, ['graph_local', 'data_local'], true)) {
        throw new RuntimeException('Unexpected graph persistence table');
    }
    unset($row['id']);
    $columns = array_keys($row);
    $db->prepare('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')->execute(array_values($row));
    return (int) $db->lastInsertId();
}
function change_graph_template(int $graph, int $template, bool $apply): void
{
    db_execute_prepared('INSERT INTO graph_templates_item (graph_template_id, local_graph_id, local_graph_template_item_id, task_item_id) VALUES (?, ?, 1, 0)', [$template, $graph]);
}
function change_data_template(int $data, int $template, array $profile): void
{
    db_execute_prepared('INSERT INTO data_template_rrd (data_template_id, local_data_id, local_data_template_rrd_id, data_source_name) VALUES (?, ?, 2, ?)', [$template, $data, 'value']);
    db_execute_prepared('INSERT INTO data_template_data (local_data_id) VALUES (?)', [$data]);
}
function graph_template_whitelist_check(int $template): bool
{
    return true;
}
function data_source_exists(...$arguments): array
{
    return [];
}
function set_config_option(...$arguments): void {}
function update_graph_title_cache(...$arguments): void {}
function update_data_source_title_cache(...$arguments): void {}
function cacti_sizeof(mixed $value): int
{
    return is_array($value) ? count($value) : 0;
}
function read_config_option(string $name): string
{
    global $outcome;
    return $name === 'automation_tree_enabled' && $outcome !== 'disabled' ? 'on' : '';
}
function automation_function_with_pid(string $name): string
{
    return $name;
}
function get_matching_graphs(array $rule, int $kind, string $filter): array
{
    return [['id' => 1]];
}
function create_all_header_nodes(int $graph, array $rule): int
{
    return 0;
}
function create_graph_node(int $graph, int $parent, array $rule): int
{
    global $db, $outcome;
    if ($outcome === 'exception') {
        throw new RuntimeException('Tree node save failed');
    }
    if ($outcome === 'failure') {
        return 0;
    }
    db_execute_prepared('INSERT INTO graph_tree_items (graph_tree_id, parent, local_graph_id) VALUES (?, ?, ?)', [$rule['tree_id'], $parent, $graph]);
    return (int) $db->lastInsertId();
}
function cacti_log(string $message, ...$arguments): void
{
    global $errors;
    if (str_starts_with($message, 'ERROR:')) {
        $errors[] = $message;
    }
}
function api_plugin_hook_function(string $hook, array $data): array
{
    global $plugins;
    $plugins[] = ['hook' => $hook, 'data' => $data];
    return $data;
}

$directory = sys_get_temp_dir() . '/kadupul-graph-hook-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
file_put_contents($directory . '/data_query.php', '<?php');
$config = ['library_path' => $directory];
$results = [];
$error = null;
try {
    if ($strict) {
        $db->beginTransaction();
    }
    for ($i = 0; $i < 2; $i++) {
        $suggested = [];
        $results[] = create_complete_graph_from_template(5, 7, [], $suggested);
    }
    if ($strict) {
        $db->commit();
    }
} catch (\Throwable $failure) {
    $error = $failure->getMessage();
    if ($db->inTransaction()) {
        $db->rollBack();
    }
} finally {
    unlink($directory . '/data_query.php');
    rmdir($directory);
}
echo json_encode(['error' => $error, 'results' => $results, 'graphs' => (int) $db->query('SELECT COUNT(*) FROM graph_local')->fetchColumn(), 'data' => (int) $db->query('SELECT COUNT(*) FROM data_local')->fetchColumn(), 'links' => (int) $db->query('SELECT COUNT(*) FROM graph_templates_item gti JOIN data_template_rrd dtr ON gti.task_item_id=dtr.id JOIN data_local dl ON dl.id=dtr.local_data_id JOIN graph_local gl ON gl.id=gti.local_graph_id WHERE dl.host_id=gl.host_id')->fetchColumn(), 'tree' => (int) $db->query('SELECT COUNT(*) FROM graph_tree_items')->fetchColumn(), 'plugins' => $plugins, 'errors' => $errors, 'transaction' => $db->inTransaction()], JSON_THROW_ON_ERROR);
