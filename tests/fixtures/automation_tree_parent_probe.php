<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace AutomationTreeParentProbe;

require dirname(__DIR__) . '/Helpers/PhpSource.php';
require dirname(__DIR__, 2) . '/include/global_constants.php';

$db = new \PDO('sqlite::memory:');
$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE graph_tree (id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO graph_tree VALUES (1), (2)');
$db->exec('CREATE TABLE graph_tree_items (id INTEGER PRIMARY KEY, graph_tree_id INTEGER, parent INTEGER,
 title TEXT, local_graph_id INTEGER DEFAULT 0, host_id INTEGER DEFAULT 0, site_id INTEGER DEFAULT 0,
 host_grouping_type INTEGER DEFAULT 1, sort_children_type INTEGER DEFAULT 1, position INTEGER DEFAULT 0)');
$db->exec("INSERT INTO graph_tree_items (id, graph_tree_id, parent, title) VALUES (10,1,0,'valid'), (20,2,0,'foreign')");
$db->exec("INSERT INTO graph_tree_items (id,graph_tree_id,parent,title,host_id) VALUES (30,1,0,'',7)");
$db->exec("INSERT INTO graph_tree_items (id,graph_tree_id,parent,title,local_graph_id,site_id) VALUES (40,1,0,'',8,0),(50,1,0,'site',0,9),(60,1,0,'',0,0),(70,1,0,NULL,0,0),(80,1,0,'0',0,0)");
$calls = [];
$logs = [];
$config = [];
$failTitle = $argv[3] ?? '';
$rule = ['id' => 1, 'name' => 'owned fixture', 'tree_id' => 1, 'tree_item_id' => (int) $argv[2],
 'leaf_type' => $argv[1] === 'graph' ? TREE_ITEM_TYPE_GRAPH : TREE_ITEM_TYPE_HOST, 'host_grouping_type' => 1];
$items = [
 ['field' => AUTOMATION_TREE_ITEM_TYPE_STRING, 'search_pattern' => 'first', 'replace_pattern' => '', 'propagate_changes' => '', 'sort_type' => 1],
 ['field' => AUTOMATION_TREE_ITEM_TYPE_STRING, 'search_pattern' => 'second', 'replace_pattern' => '', 'propagate_changes' => '', 'sort_type' => 1],
];
$automation_tree_header_types = [AUTOMATION_TREE_ITEM_TYPE_STRING => 'String'];

function query(string $sql, array $parameters = []): \PDOStatement
{
    global $db;
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement;
}
function db_fetch_cell_prepared($sql, $parameters = [])
{
    return query($sql, $parameters)->fetchColumn();
}
function db_fetch_row_prepared($sql, $parameters = [])
{
    return query($sql, $parameters)->fetch(\PDO::FETCH_ASSOC) ?: [];
}
function db_fetch_assoc_prepared($sql, $parameters = [])
{
    global $items;
    return str_contains($sql, 'automation_tree_rule_items') ? $items : query($sql, $parameters)->fetchAll(\PDO::FETCH_ASSOC);
}
function db_fetch_assoc($sql)
{
    global $rule;
    return [$rule];
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function input_validate_input_number($value)
{
    if (!is_numeric($value)) {
        throw new \RuntimeException('Invalid ID');
    }
}
function form_input_validate($value, ...$arguments)
{
    return $value;
}
function is_error_message()
{
    return false;
}
function raise_message($id)
{
    global $logs;
    $logs[] = "message:$id";
}
function html_escape($value)
{
    return htmlspecialchars($value, ENT_QUOTES);
}
function set_config_option($name, $value)
{
}
function api_tree_sort_branch($id, $tree)
{
}
function cacti_log($message, ...$arguments)
{
    global $logs;
    $logs[] = $message;
}
function automation_function_with_pid($name)
{
    return $name;
}
function build_matching_objects_filter(...$arguments)
{
    return '1=1';
}
function get_matching_hosts(...$arguments)
{
    return [['id' => 7]];
}
function get_matching_graphs(...$arguments)
{
    return [['id' => 7]];
}
function automation_string_replace(...$arguments)
{
    return ['first', 'second'];
}
function sanitize_sql_column($field)
{
    return '';
}
function sql_save($record, $table)
{
    global $db, $calls, $failTitle;
    $calls[] = $record;
    if ($failTitle !== '' && $record['title'] === $failTitle) {
        return 0;
    }
    unset($record['id']);
    query('INSERT INTO '.$table.' ('.implode(',', array_keys($record)).') VALUES ('.implode(',', array_fill(0, count($record), '?')).')', array_values($record));
    return (int) $db->lastInsertId();
}

foreach ([
 'lib/api_tree.php' => ['api_tree_item_save', 'api_tree_branch_exists', 'api_tree_get_branch_id', 'api_tree_host_exists', 'api_tree_graph_exists'],
 'lib/html_tree.php' => ['grow_dropdown_tree'],
 'lib/api_automation.php' => ['create_header_node', 'create_multi_header_node', 'create_all_header_nodes',
  'create_device_node', 'create_graph_node', 'automation_execute_device_create_tree', 'automation_execute_graph_create_tree'],
] as $file => $functions) {
    $source = file_get_contents(dirname(__DIR__, 2).'/'.$file);
    if (!is_string($source)) {
        throw new \RuntimeException('Cannot read '.$file);
    }
    foreach ($functions as $function) {
        eval('namespace '.__NAMESPACE__.';'.\test_php_function_source($source, $function));
    }
}
$before = query('SELECT * FROM graph_tree_items ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
switch ($argv[1]) {
    case 'api': $result = api_tree_item_save(0, 1, TREE_ITEM_TYPE_HEADER, $rule['tree_item_id'], 'new', 0, 0, 0, 1, 1, false);
        break;
    case 'missing-tree': $result = api_tree_item_save(0, 99, TREE_ITEM_TYPE_HEADER, 0, 'new', 0, 0, 0, 1, 1, false);
        break;
    case 'dropdown': ob_start();
        grow_dropdown_tree(1, 0, 'parent');
        $result = ob_get_clean();
        break;
    case 'device': automation_execute_device_create_tree(7);
        break;
    case 'graph': automation_execute_graph_create_tree(7);
        break;
    case 'all': $result = create_all_header_nodes(7, $rule);
        break;
    case 'multi': $item = $items[0];
        $item['field'] = 'derived';
        $result = create_multi_header_node('value', $rule, $item, $rule['tree_item_id']);
        break;
    default: throw new \RuntimeException('Unknown scenario');
}
echo json_encode(['result' => $result ?? null,'calls' => $calls,'logs' => $logs,'before' => $before,
 'after' => query('SELECT * FROM graph_tree_items ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC)], JSON_THROW_ON_ERROR);
