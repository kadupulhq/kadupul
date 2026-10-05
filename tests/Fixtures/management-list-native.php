<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Whole legacy controllers and full native policy SQL; HTTP admission, page
// chrome and unrelated graph/poller integrations are explicit fixture ports.
function management_list_fixture_run(): never
{
    global $root, $db, $scenario, $config, $item_rows, $graph_sources, $sampling_intervals;
    require_once $root . '/include/global_constants.php';
    $db->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', $parts));
    $db->exec("ALTER TABLE host ADD hostname TEXT DEFAULT ''; ALTER TABLE graph_local ADD snmp_query_graph_id INTEGER DEFAULT 0;
        ALTER TABLE graph_templates_graph ADD id INTEGER; ALTER TABLE graph_templates_graph ADD graph_template_id INTEGER DEFAULT 0;
        CREATE TABLE aggregate_graphs(local_graph_id INTEGER);
        CREATE TABLE snmp_query_graph(id INTEGER, snmp_query_id INTEGER, graph_template_id INTEGER, name TEXT);
        CREATE TABLE data_local(id INTEGER PRIMARY KEY, host_id INTEGER, data_template_id INTEGER, snmp_index TEXT DEFAULT '', snmp_query_id INTEGER DEFAULT 0, orphan INTEGER DEFAULT 0);
        CREATE TABLE data_template(id INTEGER PRIMARY KEY, name TEXT);
        CREATE TABLE data_template_data(local_data_id INTEGER PRIMARY KEY, data_template_id INTEGER, name_cache TEXT, active TEXT, rrd_step INTEGER, data_source_profile_id INTEGER);
        CREATE TABLE data_source_profiles(id INTEGER, name TEXT);
        CREATE TABLE data_template_rrd(id INTEGER, local_data_id INTEGER);
        CREATE TABLE graph_templates_item(task_item_id INTEGER, local_graph_id INTEGER);
        INSERT INTO host(id,site_id,description,hostname,disabled) VALUES(101,0,'Allowed disabled device','allowed.example','on'),(201,0,'Denied device','denied.example','');
        INSERT INTO graph_templates VALUES(102,'Allowed template'),(202,'Denied template'),(302,'Non-device template');");
    foreach ([[1001,101,102,'Allowed record'],[1002,201,202,'Denied record'],[1003,0,302,'Non-device record']] as [$id,$host,$template,$name]) {
        $db->prepare('INSERT INTO graph_local(id,host_id,graph_template_id) VALUES(?,?,?)')->execute([$id,$host,$template]);
        $db->prepare('INSERT INTO graph_templates_graph(id,local_graph_id,graph_template_id,title_cache,width,height) VALUES(?,?,?,?,100,100)')->execute([$id,$id,$template,$name]);
        $db->prepare('INSERT INTO data_local(id,host_id,data_template_id) VALUES(?,?,?)')->execute([$id,$host,$template]);
        $db->prepare('INSERT INTO data_template VALUES(?,?)')->execute([$template,$name . ' template']);
        $db->prepare("INSERT INTO data_template_data VALUES(?,?,?,'on',300,0)")->execute([$id,$template,$name]);
    }
    $db->exec('UPDATE user_auth SET policy_hosts=2,policy_graphs=2,policy_graph_templates=2 WHERE id=42;
        INSERT INTO user_auth_perms VALUES(42,3,101),(42,1,1001),(42,4,102),(42,1,1003),(42,4,302)');
    if ($scenario['empty_devices'] ?? false) $db->exec('DELETE FROM user_auth_perms');
    if ($scenario['deny_graph'] ?? false) $db->exec('DELETE FROM user_auth_perms WHERE type=1 AND item_id=1001');
    if ($scenario['deny_template'] ?? false) {
        $db->exec("DELETE FROM user_auth_perms WHERE (type=4 AND item_id=102) OR (type=1 AND item_id=1001);
        INSERT INTO graph_templates VALUES(402,'Allowed sibling template');
        INSERT INTO graph_local(id,host_id,graph_template_id) VALUES(1004,101,402);
        INSERT INTO graph_templates_graph(id,local_graph_id,graph_template_id,title_cache,width,height) VALUES(1004,1004,402,'Allowed sibling',100,100);
        INSERT INTO user_auth_perms VALUES(42,4,402)");
    }
    if ($scenario['device_count'] ?? false) {
        $insert = $db->prepare("INSERT INTO host(id,site_id,description) VALUES(?,0,'Permitted inventory record')");
        $grant = $db->prepare('INSERT INTO user_auth_perms VALUES(42,3,?)');
        for ($i = 3000; $i < 3000 + $scenario['device_count']; $i++) {
            $insert->execute([$i]);
            $grant->execute([$i]);
        }
    }
    if ($scenario['orphan_graph'] ?? false) {
        $db->exec("INSERT INTO graph_local(id,host_id,graph_template_id) VALUES(1005,999,102);
        INSERT INTO graph_templates_graph(id,local_graph_id,graph_template_id,title_cache,width,height) VALUES(1005,1005,102,'Missing owner graph',100,100);
        INSERT INTO user_auth_perms VALUES(42,1,1005)");
    }
    $_REQUEST = array_merge(['action' => '', 'rows' => 20, 'page' => 1, 'host_id' => -1, 'site_id' => -1, 'template_id' => -1,
        'rfilter' => '', 'source' => -1, 'orphans' => '', 'status' => -1, 'profile' => -1, 'local_graph_ids' => '', 'sort_column' => 'id', 'sort_direction' => 'ASC'], $scenario['request'] ?? []);
    $GLOBALS['listAdmission'] = ['graph1001' => is_graph_allowed(1001), 'device101' => is_device_allowed(101)];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $config['base_path'] = $root;
    $item_rows = [20 => 'Twenty'];
    $graph_sources = ['0' => 'None','1' => 'Query','2' => 'Template'];
    $sampling_intervals = [];
    $directory = $GLOBALS['argv'][2];
    mkdir($directory . '/include', 0700);
    mkdir($directory . '/lib', 0700);
    file_put_contents($directory . '/include/auth.php', '<?php');
    foreach (['api_aggregate','api_automation','api_data_source','api_device','api_graph','api_tree','data_query','graphs','html_graph','html_form_template','html_tree','poller','reports','rrd','template','utility','html_form'] as $name) file_put_contents($directory . '/lib/' . $name . '.php', '<?php');
    chdir($directory);
    ob_start();
    register_shutdown_function(static function () use ($directory): void {
        $html = ob_get_clean();
        foreach (glob($directory . '/lib/*.php') as $path) unlink($path);
        rmdir($directory . '/lib');
        unlink($directory . '/include/auth.php');
        rmdir($directory . '/include');
        print json_encode(['completed' => $GLOBALS['managementListCompleted'] ?? false, 'admission' => $GLOBALS['listAdmission'], 'html' => $html, 'request' => $_REQUEST, 'queries' => $GLOBALS['querySql'], 'row_counts' => $GLOBALS['queryRowCounts']], JSON_THROW_ON_ERROR);
    });
    require $root . '/' . ($scenario['resource'] === 'graph' ? 'graphs.php' : 'data_sources.php');
    $GLOBALS['managementListCompleted'] = true;
    $GLOBALS['nativeChildCoverageMarkers'] = ['native-policy-operation-returned', 'policy-session-observed', 'management-list-rendered', 'management-list-scope-observed'];
    exit;
}

final class CactiSecureHeaders
{
    public static function getNonceAttribute(): string
    {
        return '';
    }
}
function get_request_var(string $key, mixed $default = ''): mixed
{
    return $_REQUEST[$key] ?? $default;
}
function get_nfilter_request_var(string $key, mixed $default = ''): mixed
{
    return get_request_var($key, $default);
}
function get_filter_request_var(string $key, mixed ...$args): mixed
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
}
function validate_store_request_vars(mixed ...$args): void {}
function cacti_require_post_actions(array $actions): void {}
function set_default_action(): void {}
function __(string $text, mixed ...$args): string
{
    return $args ? sprintf($text, ...$args) : $text;
}
function __esc(string $text, mixed ...$args): string
{
    return html_escape(__($text, ...$args));
}
function html_escape(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}
function html_escape_request_var(string $key): string
{
    return html_escape(get_request_var($key));
}
function api_plugin_hook_function(string $name, mixed $value): mixed
{
    return $value;
}
function top_header(): void {}
function bottom_footer(): void {}
function html_start_box(mixed ...$args): void
{
    print '<table>';
}
function html_end_box(mixed ...$args): void
{
    print '</table>';
}
function html_site_filter(mixed ...$args): string
{
    return '';
}
function html_host_filter(mixed ...$args): string
{
    return '';
}
function html_nav_bar(mixed ...$args): string
{
    return '<span data-total="' . $args[4] . '"></span>';
}
function get_order_string(): string
{
    return '';
}
function form_start(mixed ...$args): void
{
    print '<form>';
}
function form_end(mixed ...$args): void
{
    print '</form>';
}
function html_header_sort_checkbox(mixed ...$args): void {}
function form_alternate_row(string $id, mixed ...$args): void
{
    print '<tr id="' . html_escape($id) . '">';
}
function form_selectable_cell(mixed $text, mixed ...$args): void
{
    print '<td>' . $text . '</td>';
}
function form_selectable_ecell(mixed $text, mixed ...$args): void
{
    print '<td>' . html_escape($text) . '</td>';
}
function form_checkbox_cell(mixed $title, mixed $id, mixed ...$args): void
{
    print '<td><input name="chk_' . (int) $id . '" type="checkbox"></td>';
}
function form_end_row(): void
{
    print '</tr>';
}
function title_trim(string $text, mixed ...$args): string
{
    return $text;
}
function filter_value(mixed $text, mixed ...$args): string
{
    return (string) $text;
}
function get_graph_template_details(mixed $id): array
{
    return ['name' => 'Fixture template', 'url' => ''];
}
function draw_actions_dropdown(mixed ...$args): void {}
function api_data_source_deletable(mixed $id): bool
{
    return true;
}
