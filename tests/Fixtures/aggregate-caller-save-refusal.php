<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = dirname(__DIR__, 2);
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/include/global_constants.php';
$page = $argv[1];
$source = $argv[2] ?? $root . '/' . $page;
$function = $page === 'aggregate_templates.php' ? 'aggregate_form_save' : ($page === 'graphs.php' ? 'form_actions' : 'form_save');
// This fixture checks response/delegation handoff. The native outer probe
// separately verifies real SQL atomicity and rollback on both engines.
$trace = ['messages' => [], 'items' => [], 'propagation' => 0, 'generation' => 0,
    'creation' => [], 'graph_creation_arguments' => [], 'mutation_results' => []];
register_shutdown_function(static function (): void {
    echo json_encode($GLOBALS['trace'], JSON_THROW_ON_ERROR);
});
$_SESSION = ['sess_user_id' => 1];
$_POST = ['save_component_template' => '1', 'save_component_graph' => '1', 'name' => 'Template',
    'graph_template_id_prev' => 1, 'graph_template_id' => 1, 'local_graph_id' => 1,
    'gprint_prefix' => '', 'gprint_format' => '1', 'graph_type' => 0, 'total' => 0,
    'total_type' => 0, 'total_prefix' => '', 'order_type' => 0, 'title_format' => 'Aggregate',
    'aggregate_graph_type' => 0, 'item_no' => 1, 'selected_items' => serialize([11]), 'drp_action' => '9'];
function isset_request_var($key)
{
    return isset($_POST[$key]);
}
function get_request_var($key, $default = '')
{
    return $_POST[$key] ?? $default;
}
function get_nfilter_request_var($key, $default = '')
{
    return get_request_var($key, $default);
}
function get_filter_request_var($key, ...$options)
{
    return get_request_var($key);
}
function set_request_var($key, $value)
{
    $_POST[$key] = $value;
}
function form_input_validate($value, ...$options)
{
    return $value;
}
function is_error_message()
{
    return false;
}
function cacti_log(...$arguments) {}
function cacti_sizeof($value)
{
    return count($value);
}
function cacti_count($value)
{
    return count($value);
}
function __($message)
{
    return $message;
}
function sql_save(...$arguments)
{
    return 1;
}
function db_execute_prepared(...$arguments)
{
    return true;
}
function db_fetch_cell_prepared(...$arguments)
{
    return 1;
}
function db_fetch_row_prepared($sql, ...$arguments)
{
    return str_contains($sql, 'FROM aggregate_graphs') ? ['aggregate_template_id' => 0,
        'template_propogation' => '', 'gprint_prefix' => '', 'gprint_format' => 'on',
        'graph_type' => 0, 'total' => 0, 'total_type' => 0, 'total_prefix' => '', 'order_type' => 0] : [];
}
function db_fetch_assoc_prepared($sql, ...$arguments)
{
    return str_contains($sql, 'FROM graph_templates_item') ? [['id' => 7, 'sequence' => 1]] : [];
}
function aggregate_validate_graph_params(...$arguments)
{
    return [];
}
function aggregate_validate_graph_items($post, &$items) {}
function aggregate_graph_templates_graph_save(...$arguments)
{
    return 1;
}
function aggregate_graph_save(...$arguments)
{
    $GLOBALS['trace']['graph_creation_arguments'][] = $arguments;
    return 42;
}
function aggregate_graph_fetch_rows($sql, $parameters = [])
{
    return db_fetch_assoc_prepared($sql, $parameters);
}
function aggregate_graph_save_row($row, $table)
{
    return sql_save($row, $table);
}
function aggregate_graph_mutation(callable $operation): bool
{
    $result = $operation();
    $GLOBALS['trace']['mutation_results'][] = $result;
    return $result === true;
}
function api_aggregate_create_from_request(array $selected_items, int &$local_graph_id): bool
{
    $before = $local_graph_id;
    $result = fixture_actual_aggregate_create_from_request($selected_items, $local_graph_id);
    $GLOBALS['trace']['creation'][] = ['selected_items' => $selected_items,
        'before' => $before, 'after' => $local_graph_id, 'result' => $result];
    return $result;
}
function sanitize_unserialize_selected_items($value)
{
    return unserialize($value, ['allowed_classes' => false]);
}
function aggregate_graph_items_save($items, $table)
{
    $GLOBALS['trace']['items'][] = ['items' => $items, 'table' => $table];
    return false;
}
function raise_message($id, $message = '', $level = null)
{
    $GLOBALS['trace']['messages'][] = [$id, $message, $level];
}
function push_out_aggregates(...$arguments)
{
    $GLOBALS['trace']['propagation']++;
}
function aggregate_create_update(...$arguments)
{
    $GLOBALS['trace']['generation']++;
}
function api_plugin_hook_function(...$arguments)
{
    throw new RuntimeException('Post-refusal plugin execution');
}
function snmpagent_graphs_action_bottom(...$arguments)
{
    throw new RuntimeException('Post-refusal SNMP propagation');
}
eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'array_rekey'));
$aggregateSource = file_get_contents($root . '/lib/aggregate.php');
if (!is_string($aggregateSource)) throw new RuntimeException('Cannot read the actual aggregate caller.');
eval(test_php_function_source($aggregateSource, 'aggregate_graph_validate_request_items'));
// Rename only the fixed first-party entry point to observe its by-reference
// result without replacing its implementation or invoking it a second time.
eval(str_replace(
    'function api_aggregate_create_from_request(',
    'function fixture_actual_aggregate_create_from_request(',
    test_php_function_source($aggregateSource, 'api_aggregate_create_from_request')
));
if ($page === 'graphs.php') {
    // This caller fixture grants only the selected graph and its persisted device.
    // Denial behavior is exercised by the branch's native authorization suite.
    function is_graph_allowed($id): bool
    {
        return (int) $id === 11;
    }
    function is_device_allowed($id): bool
    {
        return (int) $id === 1;
    }
    eval(test_php_function_source(file_get_contents($source), 'graph_edit_graph_is_allowed'));
    eval(test_php_function_source(file_get_contents($source), 'graph_edit_access_denied'));
}
eval(test_php_function_source(file_get_contents($source), $function));
$function();
