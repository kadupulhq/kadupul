<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Complete action functions, real policy functions and SQLite queries. Authentication,
// CSRF admission, plugins and confirmation rendering are explicit fixture ports.
function management_bulk_fixture_run(): never
{
    global $root, $db, $scenario, $queries, $querySql, $queryRowCounts, $config;
    require_once $root . '/tests/Helpers/PhpSource.php';
    require_once $root . '/include/global_constants.php';
    $functions = file_get_contents($root . '/lib/functions.php');
    if ($functions === false) throw new RuntimeException('Cannot read selection parser.');
    eval(test_php_function_source($functions, 'sanitize_unserialize_selected_items'));
    $db->exec("CREATE TABLE data_local(id INTEGER PRIMARY KEY,host_id INTEGER);
        CREATE TABLE native_resource_titles(id INTEGER PRIMARY KEY,name TEXT);
        INSERT INTO host(id,description,disabled) VALUES(101,'Shared permitted host','on'),(201,'Foreign host','');
        INSERT INTO graph_templates VALUES(102,'Shared template');");
    $size = $scenario['size'] ?? 4;
    $ids = range(1001, 1000 + $size);
    $graph = $db->prepare('INSERT INTO graph_local(id,host_id,graph_template_id) VALUES(?,101,102)');
    $title = $db->prepare('INSERT INTO graph_templates_graph(local_graph_id,title_cache,width,height) VALUES(?,?,100,100)');
    $data = $db->prepare('INSERT INTO data_local VALUES(?,101)');
    $name = $db->prepare('INSERT INTO native_resource_titles VALUES(?,?)');
    $device = $db->prepare("INSERT INTO host(id,description,disabled) VALUES(?,?,'on')");
    foreach ($ids as $id) {
        if ($scenario['resource'] === 'device') $device->execute([$id, 'Record ' . $id]);
        $graph->execute([$id]);
        $title->execute([$id,'Record ' . $id]);
        $data->execute([$id]);
        $name->execute([$id,'Record ' . $id]);
    }
    if ($scenario['restricted'] ?? false) {
        $db->exec('UPDATE user_auth SET policy_hosts=2,policy_graphs=2,policy_graph_templates=2 WHERE id=42;
            INSERT INTO user_auth_perms VALUES(42,3,101)');
    }
    foreach ($scenario['owners'] ?? [] as $id => $host) {
        foreach (['graph_local','data_local'] as $table) {
            $db->prepare('UPDATE ' . $table . ' SET host_id=? WHERE id=?')->execute([$host,$id]);
        }
    }
    if ($scenario['actor_locked'] ?? false) $db->exec("UPDATE user_auth SET locked='on' WHERE id=42");
    if ($scenario['actor_disabled'] ?? false) $db->exec("UPDATE user_auth SET enabled='' WHERE id=42");
    $selection = $scenario['selection'] ?? $ids;
    if ($scenario['integral_float'] ?? false) $selection[0] = (float) $selection[0];
    $_REQUEST = ['action' => 'actions', 'drp_action' => 'native_budget_probe', 'header' => 'false'];
    $_POST = ['action' => 'actions','drp_action' => 'native_budget_probe'];
    if (($scenario['phase'] ?? 'execute') === 'execute') {
        $_REQUEST['selected_items'] = serialize($selection);
        $_POST['selected_items'] = $_REQUEST['selected_items'];
    } else {
        foreach ($selection as $id) $_POST['chk_' . $id] = 'on';
    }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $GLOBALS['bulkFixture'] = ['stage' => 'denied','events' => [],'title_ids' => [],'selection' => null,'initial_selection' => $selection,'injected' => 0];
    $GLOBALS['bulkBeforeQueries'] = $queries;
    $querySql = [];
    $queryRowCounts = [];
    $GLOBALS['database_last_error'] = 'preserved previous diagnostic';
    $directory = $GLOBALS['argv'][2];
    $config['base_path'] = $root;
    register_shutdown_function(static function () use ($db, $directory): void {
        $state = $GLOBALS['bulkFixture'];
        $state['queries'] = $GLOBALS['queries'] - $GLOBALS['bulkBeforeQueries'];
        $state['owner_queries'] = count(array_filter($GLOBALS['querySql'], static fn(string $sql): bool => str_contains($sql, 'SELECT host_id')));
        $state['eligibility_rows'] = array_column(array_filter($GLOBALS['queryRowCounts'], static fn(array $row): bool => $row['bulk_eligibility'] ?? false), 'rows');
        $state['error_restored'] = $GLOBALS['database_last_error'];
        $state['logs'] = $GLOBALS['logs'];
        $state['stored_owners'] = $db->query('SELECT id,host_id FROM data_local ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $GLOBALS['nativeChildCoverageMarkers'] = ['native-policy-operation-returned','policy-session-observed','management-batch-observed','management-handoff-observed'];
        print json_encode(['result' => $state,'session' => $_SESSION], JSON_THROW_ON_ERROR);
    });
    $paths = ['graphs.php' => $scenario['resource'] === 'graph' ? ['graph_edit_graph_is_allowed', 'graph_edit_access_denied', 'form_actions'] : [],
        'data_sources.php' => $scenario['resource'] === 'data' ? ['data_source_device_is_allowed', 'data_source_access_denied', 'form_actions'] : [],
        'lib/api_data_source.php' => $scenario['resource'] === 'data' ? ['api_data_source_is_allowed'] : [],
        'host.php' => $scenario['resource'] === 'device' ? ['host_require_device_access', 'form_actions'] : []];
    foreach ($paths as $path => $names) {
        $source = file_get_contents($root . '/' . $path);
        if ($source === false) throw new RuntimeException('Cannot read actual action source.');
        foreach ($names as $name) eval(test_php_function_source($source, $name));
    }
    form_actions();
    throw new RuntimeException('Controller did not complete its native handoff.');
}

function management_bulk_fixture_after_query(string $sql): void
{
    if (!str_starts_with($sql, 'SELECT id FROM ') || !isset($GLOBALS['bulkFixture'])) return;
    $change = $GLOBALS['scenario']['generation_change'] ?? '';
    if ($change === '' || ($GLOBALS['bulkFixture']['injected'] > 0 && $change !== 'repeat')) return;
    $GLOBALS['bulkFixture']['injected']++;
    $GLOBALS['db']->exec('UPDATE user_auth SET reset_perms=reset_perms+1,policy_hosts=2,policy_graphs=2,policy_graph_templates=2 WHERE id=42; DELETE FROM user_auth_perms');
}

function get_request_var(string $key, mixed $default = ''): mixed
{
    return $_REQUEST[$key] ?? $default;
}
function get_nfilter_request_var(string $key, mixed $default = ''): mixed
{
    return get_request_var($key, $default);
}
function get_filter_request_var(string $key, mixed ...$arguments): mixed
{
    return get_request_var($key);
}
function isset_request_var(string $key): bool
{
    return isset($_REQUEST[$key]);
}
function isempty_request_var(string $key): bool
{
    return empty($_REQUEST[$key]);
}
function set_request_var(string $key, mixed $value): void
{
    $_REQUEST[$key] = $value;
    if ($key === 'selected_items' && ($GLOBALS['scenario']['owner_change_between_boundaries'] ?? false)) {
        $table = $GLOBALS['scenario']['resource'] === 'graph' ? 'graph_local' : 'data_local';
        $GLOBALS['db']->exec('UPDATE ' . $table . ' SET host_id=201 WHERE id=1001');
        $GLOBALS['bulkFixture']['injected']++;
    }
}
function set_default_action(): void
{
    $_REQUEST['action'] ??= '';
}
function cacti_require_post_actions(array $actions): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Missing fixture POST admission.');
}
function cacti_count(mixed $value): int
{
    return cacti_sizeof($value);
}
function __(string $text, mixed ...$values): string
{
    return $values ? sprintf($text, ...$values) : $text;
}
function html_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}
function input_validate_input_number(mixed $value): void
{
    if (!ctype_digit((string) $value)) throw new RuntimeException('Malformed checkbox identity');
}
function validate_store_request_vars(mixed ...$arguments): void {}
function raise_message(mixed ...$arguments): void
{
    $GLOBALS['bulkFixture']['events'][] = ['message',$arguments];
}
function get_graph_title(mixed $id): string
{
    return management_bulk_fixture_title($id);
}
function get_data_source_title(mixed $id): string
{
    return management_bulk_fixture_title($id);
}
function management_bulk_fixture_title(mixed $id): string
{
    $GLOBALS['bulkFixture']['title_ids'][] = (int) $id;
    $statement = $GLOBALS['db']->prepare('SELECT name FROM native_resource_titles WHERE id=?');
    $statement->execute([(int) $id]);
    $name = $statement->fetchColumn();
    if (!is_string($name)) throw new RuntimeException('Missing persisted confirmation title');
    return $name;
}
function top_header(): never
{
    $GLOBALS['bulkFixture']['stage'] = 'confirmation';
    $GLOBALS['bulkFixture']['selection'] = $GLOBALS['bulkFixture']['title_ids'];
    exit;
}
function api_plugin_hook_function(string $name, mixed $value): mixed
{
    if (str_ends_with($name, 'action_execute')) {
        $GLOBALS['bulkFixture']['stage'] = 'execution';
        $GLOBALS['bulkFixture']['selection'] = unserialize(get_request_var('selected_items'), ['allowed_classes' => false]);
        $GLOBALS['bulkFixture']['events'][] = [$name,$GLOBALS['bulkFixture']['selection']];
    } elseif (str_ends_with($name, 'action_bottom')) {
        $GLOBALS['bulkFixture']['events'][] = [$name,$value[1]];
    }
    return $value;
}
function snmpagent_graphs_action_bottom(array $value): void
{
    $GLOBALS['bulkFixture']['events'][] = ['snmp',$value[1]];
}
function snmpagent_data_source_action_bottom(array $value): void
{
    $GLOBALS['bulkFixture']['events'][] = ['snmp',$value[1]];
}

function snmpagent_device_action_bottom(array $value): void
{
    $GLOBALS['bulkFixture']['events'][] = ['snmp',$value[1]];
}
