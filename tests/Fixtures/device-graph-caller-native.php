<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Physical controllers and policy SQL; bootstrap admission and external workers
// are explicit ports. Every scenario owns its SQLite database and PHP server.
$root = getenv('DEVICE_GRAPH_CALLER_ROOT');
$directory = getenv('DEVICE_GRAPH_CALLER_DIRECTORY');
$scenario = json_decode(getenv('DEVICE_GRAPH_CALLER_SCENARIO'), true, 512, JSON_THROW_ON_ERROR);
require_once $root . '/tests/Helpers/PhpSource.php';
require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
require_once $root . '/tests/Helpers/DeviceGraphCallerCoverageRegistration.php';
require_once $root . '/include/global_constants.php';
require_once $root . '/lib/html_utility.php';
require_once $root . '/lib/auth.php';
require_once $root . '/lib/graph_template_input.php';
foreach (['check_changed','get_current_page'] as $function) eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), $function));
foreach (['html_escape_charset','html_escape','html_escape_request_var'] as $function) eval(test_php_function_source(file_get_contents($root . '/lib/html.php'), $function));
$events = $messages = $logs = $reads = [];
$db = new PDO('sqlite:' . $directory . '/fixture.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->sqliteCreateFunction('UNIX_TIMESTAMP', static fn($value) => strtotime($value));
$db->sqliteCreateFunction('FROM_UNIXTIME', static fn($value) => gmdate('Y-m-d H:i:s', (int) $value));
$db->sqliteCreateFunction('IF', static fn($test, $yes, $no) => $test ? $yes : $no);
$db->exec("CREATE TABLE user_auth(id INTEGER PRIMARY KEY,username TEXT,realm INTEGER DEFAULT 0,enabled TEXT DEFAULT 'on',locked TEXT DEFAULT '',reset_perms INTEGER DEFAULT 0,show_tree TEXT DEFAULT 'on',show_list TEXT DEFAULT 'on',show_preview TEXT DEFAULT 'on',graph_settings TEXT DEFAULT 'on',policy_hosts INTEGER DEFAULT 2,policy_graphs INTEGER DEFAULT 2,policy_graph_templates INTEGER DEFAULT 2,policy_trees INTEGER DEFAULT 1);
 CREATE TABLE user_auth_perms(user_id INTEGER,type INTEGER,item_id INTEGER);
 CREATE TABLE user_auth_group(id INTEGER,name TEXT DEFAULT 'native group',enabled TEXT,policy_hosts INTEGER,policy_graphs INTEGER,policy_graph_templates INTEGER,policy_trees INTEGER DEFAULT 1);
 CREATE TABLE user_auth_group_members(user_id INTEGER,group_id INTEGER);
 CREATE TABLE user_auth_group_perms(group_id INTEGER,type INTEGER,item_id INTEGER);
 CREATE TABLE user_auth_realm(user_id INTEGER,realm_id INTEGER);
 CREATE TABLE user_auth_group_realm(group_id INTEGER,realm_id INTEGER);
 CREATE TABLE host(id INTEGER PRIMARY KEY,description TEXT,hostname TEXT,disabled TEXT DEFAULT 'on',deleted TEXT DEFAULT '',host_template_id INTEGER DEFAULT 0,poller_id INTEGER DEFAULT 1,site_id INTEGER DEFAULT 0);
 CREATE TABLE host_template(id INTEGER PRIMARY KEY,name TEXT);
 CREATE TABLE graph_local(id INTEGER PRIMARY KEY,host_id INTEGER,graph_template_id INTEGER,snmp_query_id INTEGER DEFAULT 0);
 CREATE TABLE graph_templates(id INTEGER PRIMARY KEY,name TEXT,multiple TEXT DEFAULT '');
 CREATE TABLE graph_templates_graph(id INTEGER PRIMARY KEY,local_graph_id INTEGER,graph_template_id INTEGER,title_cache TEXT);
 CREATE TABLE graph_templates_item(id INTEGER PRIMARY KEY,graph_template_id INTEGER,local_graph_id INTEGER,task_item_id INTEGER);
 CREATE TABLE graph_template_input(id INTEGER,graph_template_id INTEGER,column_name TEXT);
 CREATE TABLE data_local(id INTEGER PRIMARY KEY,host_id INTEGER);
 CREATE TABLE host_snmp_cache(host_id INTEGER,snmp_query_id INTEGER,field_name TEXT,field_value TEXT);
 CREATE TABLE host_snmp_query(host_id INTEGER,snmp_query_id INTEGER,last_run INTEGER DEFAULT 1,reindex_method INTEGER DEFAULT 1);
 CREATE TABLE host_graph(host_id INTEGER,graph_template_id INTEGER,PRIMARY KEY(host_id,graph_template_id));
 CREATE TABLE snmp_query(id INTEGER PRIMARY KEY,name TEXT);
 CREATE TABLE snmp_query_graph(id INTEGER PRIMARY KEY,graph_template_id INTEGER,name TEXT);
 CREATE TABLE sites(id INTEGER PRIMARY KEY);
 CREATE TABLE settings(name TEXT PRIMARY KEY,value TEXT);
 CREATE TABLE user_auth_row_cache(user_id INTEGER,class TEXT,hash TEXT,total_rows INTEGER,time TEXT,PRIMARY KEY(user_id,class,hash));
 INSERT INTO user_auth(id,username) VALUES(42,'actor');
 INSERT INTO user_auth_realm VALUES(42,3),(42,5),(42,15);
 INSERT INTO user_auth_perms VALUES(42,3,12);
 INSERT INTO host(id,description,hostname) VALUES(11,'A denied device','foreign-a'),(12,'B allowed disabled device','allowed'),(13,'C denied device','foreign-c');
 INSERT INTO host_template VALUES(0,'None');
 INSERT INTO graph_templates VALUES(5,'Native template','');
 INSERT INTO graph_templates_graph VALUES(5,0,5,'Template');
 INSERT INTO snmp_query VALUES(7,'Native query');
 INSERT INTO host_snmp_query(host_id,snmp_query_id) VALUES(12,7),(13,7);
 INSERT INTO host_snmp_cache VALUES(12,7,'sample','unchanged'),(13,7,'sample','unchanged');");
if ($scenario['no_devices'] ?? false) $db->exec('DELETE FROM user_auth_perms');
if ($scenario['group'] ?? false) $db->exec("DELETE FROM user_auth_perms; INSERT INTO user_auth_group(id,enabled,policy_hosts,policy_graphs,policy_graph_templates) VALUES(1,'on',2,2,2); INSERT INTO user_auth_group_members VALUES(42,1); INSERT INTO user_auth_group_perms VALUES(1,3,12)");
if (($scenario['policy'] ?? 2) === 1) $db->exec('UPDATE user_auth SET policy_hosts=1,policy_graphs=1,policy_graph_templates=1;DELETE FROM user_auth_perms;INSERT INTO user_auth_perms VALUES(42,3,11),(42,3,13)');
$config = ['base_path' => $directory, 'library_path' => $directory . '/lib', 'include_path' => $directory . '/include', 'url_path' => '/cacti/', 'poller_id' => 1];
$_SESSION = ['sess_user_id' => 42] + ($scenario['session'] ?? []);
$item_rows = [10 => '10'];
$fields_host_edit = [];
function csrf_startup()
{
    csrf_conf('cookie', false);
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'owned-device-caller-fixture');
}
$nativePostedFields = $_POST;
$_POST = [];
require_once $root . '/include/vendor/csrf/csrf-magic.php';
$_POST = $nativePostedFields;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') $_POST['__csrf_magic'] = csrf_get_tokens();
function __($text, ...$args)
{
    return $args ? vsprintf($text, $args) : $text;
}
function __esc($text, ...$args)
{
    return html_escape(__($text, ...$args));
}
function read_config_option($name)
{
    return ['auth_method' => $GLOBALS['scenario']['auth_method'] ?? 1,'graph_auth_method' => $GLOBALS['scenario']['graph_auth_method'] ?? 3,'num_rows_table' => 10,'default_graphs_new_dropdown' => -3,'grds_creation_method' => 0][$name] ?? '';
}
function read_user_setting($name, ...$args)
{
    return $name === 'hide_disabled' ? 'on' : ($args[0] ?? '');
}
function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}
function array_rekey($rows, $key, $value)
{
    return is_array($value) ? array_column($rows, null, $key) : array_column($rows, $value, $key);
}
function db_fetch_assoc_prepared($sql, $params = [])
{
    $GLOBALS['reads'][] = [$sql,$params];
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $params = [])
{
    $rows = db_fetch_assoc_prepared($sql, $params);
    return $rows ? reset($rows[0]) : false;
}
function db_fetch_row_prepared($sql, $params = [])
{
    return db_fetch_assoc_prepared($sql, $params)[0] ?? [];
}
function db_fetch_assoc($sql)
{
    return db_fetch_assoc_prepared($sql);
}
function db_fetch_cell($sql)
{
    return db_fetch_cell_prepared($sql);
}
function db_execute_prepared($sql, $params = [])
{
    if ($GLOBALS['scenario']['graph_handoff'] ?? false) $GLOBALS['graphHandoffWrites'][] = [$sql, $params];
    if (preg_match("/^REPLACE INTO settings SET value = \?, name='([a-z0-9_]+)'$/D", $sql, $match)) {
        $sql = 'REPLACE INTO settings(name,value) VALUES(?,?)';
        $params = [$match[1],$params[0]];
    }
    $sql = str_replace('INSERT IGNORE INTO host_graph', 'INSERT OR IGNORE INTO host_graph', $sql);
    $q = $GLOBALS['db']->prepare($sql);
    return $q->execute($params);
}
function cacti_log($message, $output = false, $facility = '')
{
    $GLOBALS['logs'][] = [$message,$facility];
}
function raise_message($name, ...$args)
{
    $GLOBALS['messages'][] = [$name,$args];
}
function is_error_message()
{
    return false;
}
function kill_session_var($name)
{
    unset($_SESSION[$name]);
}
function top_header() {}
function bottom_footer() {}
function html_start_box($title, ...$args)
{
    echo '<h2>' . $title . '</h2>';
    $GLOBALS['events'][] = ['box',$title];
}
function html_end_box(...$args) {}
function form_start($page, $name = '', ...$args)
{
    echo '<form action="' . html_escape($page) . '" id="' . html_escape($name) . '">';
}
function form_end(...$args)
{
    echo '</form>';
}
function draw_edit_form($args)
{
    echo '<input name="description" value="">';
    throw new DeviceGraphFixtureCompleted('edit rendered');
}
function api_plugin_hook($name)
{
    if ($name === 'host_edit_top') $GLOBALS['events'][] = ['hook',$name];
}
function api_plugin_hook_function($name, $value = null)
{
    if (!in_array($name, ['device_action_array','graphs_action_array'], true)) $GLOBALS['events'][] = ['hook',$name,$value];
    return $value;
}
function is_device_debug_enabled($id)
{
    return false;
}
function enable_device_debug($id)
{
    $GLOBALS['events'][] = ['debug-enable',$id];
}
function disable_device_debug($id)
{
    $GLOBALS['events'][] = ['debug-disable',$id];
}
if (!($scenario['api'] ?? false)) {
    function api_device_ping_device($id)
    {
        $GLOBALS['events'][] = ['ping',$id];
    }
}
function push_out_host($id, ...$args)
{
    $GLOBALS['events'][] = ['repopulate',$id];
}
function run_data_query($host, $query)
{
    $GLOBALS['events'][] = ['query',$host,$query];
    db_execute_prepared('UPDATE host_snmp_query SET last_run=2 WHERE host_id=? AND snmp_query_id=?', [$host,$query]);
    db_execute_prepared('UPDATE host_snmp_cache SET field_value=? WHERE host_id=? AND snmp_query_id=?', ['rerun',$host,$query]);
}
if (!($scenario['api'] ?? false)) {
    function api_device_save(...$args)
    {
        $GLOBALS['events'][] = ['device-save',$args[0]];
        if ($GLOBALS['scenario']['save_failure'] ?? false) return false;
        $id = (int) $args[0];
        if ($id === 0) {
            db_execute_prepared('INSERT INTO host(description,hostname,disabled) VALUES(?,?,?)', [$args[2],$args[3],$args[11]]);
            return (int) $GLOBALS['db']->lastInsertId();
        }
        db_execute_prepared('UPDATE host SET description=? WHERE id=?', [$args[2],$id]);
        return $id;
    }
}
eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'sanitize_unserialize_selected_items'));
if (!($scenario['api'] ?? false)) {
    function api_device_enable_devices($ids)
    {
        $GLOBALS['events'][] = ['bulk-enable',$ids];
    }
}
if (!($scenario['api'] ?? false)) {
    function api_device_disable_devices($ids)
    {
        $GLOBALS['events'][] = ['bulk-disable',$ids];
        return true;
    }
}
if (!($scenario['api'] ?? false)) {
    function api_device_remove_multi($ids, $type)
    {
        $GLOBALS['events'][] = ['bulk-delete',$ids,$type];
    }
}
if (!($scenario['api'] ?? false)) {
    function api_device_change_options($ids, $fields)
    {
        $GLOBALS['events'][] = ['bulk-change',$ids];
    }
}
if (!($scenario['api'] ?? false)) {
    function api_device_clear_statistics($ids)
    {
        $GLOBALS['events'][] = ['bulk-clear',$ids];
    }
}
if (!($scenario['api'] ?? false)) {
    function api_device_sync_device_templates($ids)
    {
        $GLOBALS['events'][] = ['bulk-sync',$ids];
    }
}
function reports_add_devices($report, $ids, $span, $align)
{
    $GLOBALS['events'][] = ['bulk-report',$ids];
    return true;
}
function automation_update_device($host)
{
    $GLOBALS['events'][] = ['bulk-automation',$host];
}
function api_tree_item_save(...$args)
{
    $GLOBALS['events'][] = ['bulk-tree',$args[6]];
    return 1;
}
function snmpagent_device_action_bottom($args)
{
    $GLOBALS['events'][] = ['snmp-bottom',$args];
}
if (!($scenario['api'] ?? false)) {
    function api_device_dq_add($host, $query, $method)
    {
        $GLOBALS['events'][] = ['query-add',$host,$query,$method];
    }
}
if (!($scenario['api'] ?? false)) {
    function api_device_dq_remove($host, $query)
    {
        $GLOBALS['events'][] = ['query-remove',$host,$query];
    }
}
if (!($scenario['api'] ?? false)) {
    function api_device_dq_change($host, $query, $method)
    {
        $GLOBALS['events'][] = ['query-change',$host,$query,$method];
    }
}
if (!($scenario['api'] ?? false)) {
    function api_device_gt_remove($host, $template)
    {
        $GLOBALS['events'][] = ['gt-remove',$host,$template];
    }
}
function automation_hook_graph_template($host, $template)
{
    $GLOBALS['events'][] = ['gt-add',$host,$template];
}
function form_hidden_box($name, $value, $default)
{
    echo '<input type="hidden" name="' . html_escape($name) . '" value="' . html_escape($value) . '">';
}
if (!($scenario['native_dropdown'] ?? false)) {
function form_dropdown($name, $items, $label, $id, ...$args)
{
    echo '<select name="' . html_escape($name) . '">';
    foreach ($items as $item) echo '<option value="' . html_escape($item[$id]) . '">' . html_escape($item[$label]) . '</option>';
    echo '</select>';
}
}
function form_save_button($return)
{
    echo '<button type="submit">Create</button>';
}
function html_graph_custom_data(...$args)
{
    $GLOBALS['events'][] = ['graph-detail',$args];
    if ($GLOBALS['scenario']['prompt'] ?? true) {
        form_start('graphs_new.php', 'new_graphs');
        echo '<input name="g_0_5_title" value="Native graph">';
        return [1];
    } return [0];
}
function input_validate_input_number($value)
{
    if (!ctype_digit((string) $value)) throw new RuntimeException('Unexpected native identifier');
}
function cacti_unserialize($text)
{
    return unserialize($text, ['allowed_classes' => false]);
}
function debug_log_clear($name) {}
function debug_log_insert(...$args) {}
function test_data_sources(...$args)
{
    return true;
}
function graph_template_whitelist_check($id)
{
    return true;
}
function get_graph_title($id)
{
    return 'Created graph';
}
function create_complete_graph_from_template($template, $host, $query, &$values)
{
    $GLOBALS['events'][] = ['graph-create',$template,$host,$query,$values];
    db_execute_prepared('INSERT INTO graph_local(host_id,graph_template_id) VALUES(?,?)', [$host,$template]);
    $id = (int) $GLOBALS['db']->lastInsertId();
    db_execute_prepared('INSERT INTO data_local(host_id) VALUES(?)', [$host]);
    return ['local_graph_id' => $id,'local_data_id' => [(int) $GLOBALS['db']->lastInsertId()]];
}
function html_host_filter($id, ...$args)
{
    return '<input name="host_id" value="' . html_escape($id) . '">';
}
function die_html_input_error(...$args)
{
    http_response_code(400);
    exit;
}
final class DeviceGraphFixtureCompleted extends RuntimeException {}
final class CactiSecureHeaders
{
    public static function getNonceAttribute()
    {
        return '';
    }
}
if (getenv('DEVICE_GRAPH_CALLER_COVERAGE') === '1') {
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/device-graph-caller-native.php', getenv('DEVICE_GRAPH_CALLER_SCENARIO'), DeviceGraphCallerCoverageRegistration::SOURCES);
    define('DEVICE_GRAPH_CALLER_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
register_shutdown_function(static function () use ($directory, $db) {
    $fatal = error_get_last();
    $state = ['fatal' => $fatal,'events' => $GLOBALS['events'],'messages' => $GLOBALS['messages'],'logs' => $GLOBALS['logs'],'reads' => $GLOBALS['reads'],'session' => $_SESSION,'api_result' => $GLOBALS['deviceApiResult'] ?? null,'api_error' => $GLOBALS['deviceApiError'] ?? null,'sapi' => PHP_SAPI,
        'hosts' => $db->query('SELECT * FROM host ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'graphs' => $db->query('SELECT * FROM graph_local')->fetchAll(PDO::FETCH_ASSOC),'data' => $db->query('SELECT * FROM data_local')->fetchAll(PDO::FETCH_ASSOC),
        'query' => $db->query('SELECT * FROM host_snmp_query')->fetchAll(PDO::FETCH_ASSOC),'cache' => $db->query('SELECT * FROM host_snmp_cache')->fetchAll(PDO::FETCH_ASSOC)];
    if ($GLOBALS['scenario']['graph_handoff'] ?? false) $state += graph_handoff_fixture_state();
    file_put_contents($directory . '/state.json', json_encode($state, JSON_THROW_ON_ERROR));
    if ($fatal === null || !in_array($fatal['type'], [E_ERROR,E_PARSE,E_COMPILE_ERROR,E_CORE_ERROR], true)) $GLOBALS['nativeChildCoverageMarkers'] = ['caller-outcome-observed','caller-persisted-state-observed'];
});
// Controllers load their empty module ports relative to the owned cwd. Template
// creation is an explicit persistence handoff, not an RRDtool integration claim.
foreach (['lib/template.php' => ['create_save_graph'],'lib/html_graph.php' => ['html_graph_new_graphs']] as $file => $functions) foreach ($functions as $name) eval(test_php_function_source(file_get_contents($root . '/' . $file), $name));

if ($scenario['graph_handoff'] ?? false) require $root . '/tests/Fixtures/graph-save-dependent-native.php';

if ($scenario['api'] ?? false) {
    require_once $root . '/src/Inventory/Infrastructure/Legacy/LegacyDeviceSiteWriter.php';
    require_once $root . '/lib/api_device.php';
    $database_hostname = 'owned';
    $database_port = '0';
    $database_default = 'fixture';
    $database_sessions = ['owned:0:fixture' => $db];
    $values = ['id' => $scenario['id'] ?? 12,'device_template_id' => 0,'description' => 'API changed','hostname' => 'localhost','snmp_community' => '','snmp_version' => 1,'snmp_username' => '','snmp_password' => '','snmp_port' => 161,'snmp_timeout' => 500,'disabled' => '','availability_method' => 1,'ping_method' => 1,'ping_port' => 0,'ping_timeout' => 500,'ping_retries' => 1,'notes' => '','snmp_auth_protocol' => '','snmp_priv_passphrase' => '','snmp_priv_protocol' => '','snmp_context' => '','snmp_engine_id' => '','max_oids' => 5,'device_threads' => 1,'poller_id' => 1,'site_id' => 0,'external_id' => '','location' => '','bulk_walk_size' => -1];
    $reflection = new ReflectionFunction('api_device_save');
    $arguments = [];
    foreach ($reflection->getParameters() as $parameter) {
        $name = $parameter->getName();
        $arguments[] = $values[$name] ?? $parameter->getDefaultValue();
        if ($name !== 'id' && !in_array($name, ['device_template_id','create_only','expected_site_id'], true) && !in_array($name, array_column($db->query('PRAGMA table_info(host)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true)) $db->exec('ALTER TABLE host ADD COLUMN ' . $name . ' TEXT DEFAULT ""');
    }
    try {
        if (!($scenario['api_form'] ?? false)) $deviceApiResult = api_device_save(...$arguments);
    } catch (Throwable $error) {
        $deviceApiError = get_class($error) . ':' . $error->getMessage();
    }
}
function form_input_validate($value, ...$args)
{
    return $value;
}
function sql_save($fields, $table, $key = 'id', $replace = true, $connection = false)
{
    if ($GLOBALS['scenario']['graph_handoff'] ?? false) return graph_handoff_fixture_save($fields, $table);
    if ($table !== 'host' || $connection !== $GLOBALS['db'] || !$connection->inTransaction()) throw new RuntimeException('Invalid native device write boundary');
    if ($GLOBALS['scenario']['api_save_failure'] ?? false) return false;
    $id = (int) $fields['id'];
    unset($fields['id']);
    $columns = array_keys($fields);
    if ($id === 0) {
        $q = $connection->prepare('INSERT INTO host(' . implode(',', $columns) . ') VALUES(' . implode(',', array_fill(0, count($columns), '?')) . ')');
        $q->execute(array_values($fields));
        return (int) $connection->lastInsertId();
    }
    $q = $connection->prepare('UPDATE host SET ' . implode(',', array_map(static fn($column) => $column . '=?', $columns)) . ' WHERE id=?');
    $q->execute([...array_values($fields),$id]);
    return $id;
}
function update_data_source_title_cache_from_host($id)
{
    $GLOBALS['events'][] = ['data-title',$id];
}
function update_graph_title_cache_from_host($id)
{
    $GLOBALS['events'][] = ['graph-title',$id];
}
function set_config_option($name, $value)
{
    $GLOBALS['events'][] = ['config',$name];
}
function snmpagent_api_device_new($fields)
{
    $GLOBALS['events'][] = ['device-new',$fields['id']];
}
function automation_execute_device_create_tree($id)
{
    $GLOBALS['events'][] = ['automation-new',$id];
}
