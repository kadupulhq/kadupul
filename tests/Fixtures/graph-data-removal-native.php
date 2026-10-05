<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** SQLite exercises persisted mutation semantics, not MySQL engine/locking guarantees. */
final class GraphDataRemovalFixtureDatabase extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->sqliteCreateFunction('DATABASE', static fn() => 'auth');
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : parent::getAttribute($attribute);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $GLOBALS['removalFixtureNativeQueries'] = ($GLOBALS['removalFixtureNativeQueries'] ?? 0) + 1;
        $query = str_replace(' FOR UPDATE', '', $query);
        $query = preg_replace('/ON DUPLICATE KEY UPDATE `?value`?\s*=\s*VALUES\(`?value`?\)/i', 'ON CONFLICT(name) DO UPDATE SET value=excluded.value', $query);
        if (preg_match('/REPLACE INTO settings\s+SET value = \?, name=\x27([^\x27]+)\x27/s', $query, $match)) {
            $query = "INSERT OR REPLACE INTO settings(value,name) VALUES (?, '" . $match[1] . "')";
        }
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $GLOBALS['removalFixtureNativeQueries'] = ($GLOBALS['removalFixtureNativeQueries'] ?? 0) + 1;
        if (preg_match('/^SHOW CREATE TABLE `([a-z_]+)`$/D', $query, $match)) {
            $engine = in_array($match[1], ['data_source_stats_hourly_cache','data_source_stats_hourly_last'], true) ? 'MEMORY' : 'InnoDB';
            $query = "SELECT 'CREATE TABLE " . $match[1] . " () ENGINE=$engine' AS 'Create Table' FROM sqlite_master WHERE name='" . $match[1] . "'";
        } elseif (preg_match('/^SHOW TABLE STATUS WHERE Name = \x27([a-z_]+)\x27$/D', $query, $match)) {
            $engine = in_array($match[1], ['data_source_stats_hourly_cache','data_source_stats_hourly_last'], true) ? 'MEMORY' : 'InnoDB';
            $query = "SELECT name AS Name, '$engine' AS Engine FROM sqlite_master WHERE name='" . $match[1] . "'";
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

function graph_data_removal_fixture_run(): never
{
    global $db, $scenario, $root, $config, $database_hostname, $database_port, $database_default, $database_sessions;
    require_once $root . '/tests/Helpers/PhpSource.php';
    eval(test_php_function_source(file_get_contents($root . '/lib/html_form.php'), 'form_radio_button')); // nosemgrep: php.lang.security.eval-use.eval-use
    eval(test_php_function_source(file_get_contents($root . '/lib/poller.php'), 'get_remote_poller_ids_from_data_sources')); // nosemgrep: php.lang.security.eval-use.eval-use
    require_once $root . '/lib/graph_data_removal.php';
    require_once $root . '/lib/api_graph.php';
    require_once $root . '/lib/api_data_source.php';
    require_once $root . '/include/global_constants.php';
    $database_hostname = 'fixture';
    $database_port = 0;
    $database_default = 'auth';
    $database_sessions = array('fixture:0:auth' => $db);
    $config['base_path'] = $root;
    $db->exec("ALTER TABLE host ADD COLUMN poller_id INTEGER DEFAULT 1;
        ALTER TABLE reports_items ADD COLUMN local_graph_id INTEGER DEFAULT 0;
        CREATE TABLE data_local(id INTEGER PRIMARY KEY, host_id INTEGER NOT NULL);
        CREATE TABLE data_template_data(id INTEGER PRIMARY KEY, local_data_id INTEGER, name_cache TEXT, data_source_path TEXT);
        CREATE TABLE data_template_rrd(id INTEGER PRIMARY KEY, local_data_id INTEGER);
        CREATE TABLE graph_templates_item(id INTEGER PRIMARY KEY, local_graph_id INTEGER, task_item_id INTEGER, hash TEXT DEFAULT '');
        CREATE TABLE data_input_data(data_template_data_id INTEGER, value TEXT);
        CREATE TABLE aggregate_graphs(id INTEGER PRIMARY KEY, local_graph_id INTEGER);
        CREATE TABLE aggregate_graphs_items(aggregate_graph_id INTEGER, local_graph_id INTEGER);
        CREATE TABLE aggregate_graphs_graph_item(aggregate_graph_id INTEGER, graph_templates_item_id INTEGER);
        CREATE TABLE cdef(id INTEGER); CREATE TABLE cdef_items(cdef_id INTEGER);
        CREATE TABLE settings(name TEXT PRIMARY KEY, value TEXT);
        CREATE TABLE data_debug(datasource INTEGER);
        CREATE TABLE data_source_purge_action(local_data_id INTEGER, name TEXT, action TEXT);
        CREATE TABLE caller_work(value INTEGER);
        INSERT INTO host(id,description,poller_id) VALUES(100,'allowed',1),(101,'foreign',1),(102,'other allowed',1);
        INSERT INTO graph_templates(id,name) VALUES(10,'template');
        INSERT INTO graph_local(id,host_id,graph_template_id) VALUES(200,100,10),(201,101,10),(202,100,10),(203,0,0);
        INSERT INTO graph_templates_graph(local_graph_id,title_cache,width,height) VALUES(200,'allowed graph',1,1),(201,'foreign graph secret',1,1),(202,'aggregate parent secret',1,1),(203,'device-free graph',1,1);
        INSERT INTO data_local VALUES(300,100),(301,101),(302,0),(303,102);
        INSERT INTO data_template_data VALUES(400,300,'allowed source','owned.rrd'),(401,301,'foreign source secret','foreign.rrd'),(402,302,'device-free source','free.rrd'),(403,303,'other allowed source','other.rrd');
        INSERT INTO data_template_rrd VALUES(500,300),(501,301),(502,302),(503,303);
        INSERT INTO data_input_data VALUES(400,'owned'),(401,'foreign');
        INSERT INTO graph_templates_item(id,local_graph_id,task_item_id) VALUES(600,200,500),(601,201,501),(602,203,502);");
    foreach (array('poller_item','data_source_stats_daily','data_source_stats_hourly','data_source_stats_hourly_cache','data_source_stats_hourly_last','data_source_stats_monthly','data_source_stats_weekly','data_source_stats_yearly','poller_output','poller_output_boost') as $table) {
        $db->exec('CREATE TABLE ' . $table . '(local_data_id INTEGER)');
        $db->exec('INSERT INTO ' . $table . ' VALUES(300),(301),(302)');
    }
    foreach ($scenario['granted_realms'] ?? array(3,5) as $realm) $db->exec('INSERT INTO user_auth_realm VALUES(42,' . (int) $realm . ')');
    $db->exec('UPDATE user_auth SET policy_hosts=2,policy_graphs=2,policy_graph_templates=2 WHERE id=42');
    $db->exec('INSERT INTO user_auth_perms VALUES(42,3,100),(42,3,102)');
    if ($scenario['denied_source'] ?? false) $db->exec('UPDATE graph_templates_item SET task_item_id=501 WHERE id=600');
    if ($scenario['remove_foreign_item'] ?? false) $db->exec('DELETE FROM graph_templates_item WHERE id=601');
    if ($scenario['denied_graph'] ?? false) $db->exec('UPDATE graph_templates_item SET task_item_id=500 WHERE id=601');
    if ($scenario['denied_graph_policy'] ?? false) $db->exec('INSERT INTO user_auth_perms VALUES(42,1,200)');
    if ($scenario['aggregate'] ?? false) {
        $db->exec('INSERT INTO aggregate_graphs VALUES(700,202); INSERT INTO aggregate_graphs_items VALUES(700,200)');
        if ($scenario['denied_aggregate'] ?? false) $db->exec('UPDATE graph_local SET host_id=101 WHERE id=202');
    }
    if ($scenario['remote'] ?? false) $db->exec('UPDATE host SET poller_id=3 WHERE id=100');
    if (($scenario['batch_size'] ?? 0) > 0) {
        $insert = $db->prepare('INSERT INTO data_local(id,host_id) VALUES(?,100)');
        for ($id = 1000; $id < 1000 + $scenario['batch_size']; $id++) $insert->execute(array($id));
        $scenario['ids'] = range(1000, 999 + $scenario['batch_size']);
    }
    if (($scenario['dependent_items'] ?? 0) > 0) {
        $insert = $db->prepare('INSERT INTO graph_templates_item(id,local_graph_id,task_item_id) VALUES(?,200,500)');
        for ($id = 1000; $id < 1000 + $scenario['dependent_items']; $id++) $insert->execute(array($id));
    }
    $_POST = array('drp_action' => '1', 'delete_type' => array_key_exists('mode', $scenario) ? $scenario['mode'] : 1);
    if ($scenario['omit_mode'] ?? false) unset($_POST['delete_type']);
    $ids = $scenario['ids'] ?? array(($scenario['resource'] ?? 'graph') === 'graph' ? 200 : 300);
    if ($scenario['confirm'] ?? false) {
        foreach ($ids as $id) $_POST['chk_' . $id] = 'on';
    } else $_POST['selected_items'] = serialize($ids);
    $GLOBALS['removalFixtureHooks'] = array();
    $GLOBALS['removalFixtureMessages'] = array();
    $GLOBALS['removalFixtureReads'] = array();
    $GLOBALS['removalFixtureWrites'] = array();
    $GLOBALS['removalFixtureRemoteCalls'] = array();
    $GLOBALS['removalFixtureRemote'] = null;
    if ($scenario['remote'] ?? false) {
        $remote = new GraphDataRemovalFixtureDatabase();
        foreach ($db->query("SELECT name,sql FROM sqlite_master WHERE type='table' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $table) {
            $remote->exec($table['sql']);
            foreach ($db->query('SELECT * FROM ' . $table['name'])->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $remote->prepare('INSERT INTO ' . $table['name'] . ' VALUES(' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
            }
        }
        $GLOBALS['removalFixtureRemote'] = $remote;
    }
    $GLOBALS['removalFixtureOutput'] = '';
    if ($scenario['caller_transaction'] ?? false) {
        $db->beginTransaction();
        $db->exec('INSERT INTO caller_work VALUES(7)');
    }
    register_shutdown_function(static function (): void {
        $db = $GLOBALS['db'];
        $nativeQueries = $GLOBALS['removalFixtureNativeQueries'];
        $state = array('graphs' => array_column($db->query('SELECT id FROM graph_local ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'id'),
            'sources' => array_column($db->query('SELECT id FROM data_local ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'id'),
            'items' => array_column($db->query('SELECT id FROM graph_templates_item ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'id'),
            'inputs' => $db->query('SELECT data_template_data_id,value FROM data_input_data ORDER BY data_template_data_id')->fetchAll(PDO::FETCH_ASSOC),
            'caller' => (int) $db->query('SELECT COUNT(*) FROM caller_work')->fetchColumn(), 'transaction' => $db->inTransaction(),
            'hooks' => $GLOBALS['removalFixtureHooks'], 'messages' => $GLOBALS['removalFixtureMessages'],
            'reads' => $GLOBALS['removalFixtureReads'], 'writes' => $GLOBALS['removalFixtureWrites'],
            'remote_calls' => $GLOBALS['removalFixtureRemoteCalls'], 'queries' => $GLOBALS['queries'], 'logs' => $GLOBALS['logs'],
            'protected_queries' => array_values(array_filter($GLOBALS['querySql'], static fn($sql) => preg_match('/SELECT[^;]*(?:title_cache|name_cache)/si', $sql) === 1)),
            'html' => ob_get_clean(), 'native_queries' => $nativeQueries);
        if ($GLOBALS['removalFixtureRemote'] instanceof PDO) {
            $remote = $GLOBALS['removalFixtureRemote'];
            $state['remote_sources'] = array_column($remote->query('SELECT id FROM data_local ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'id');
            $state['remote_items'] = array_column($remote->query('SELECT id FROM graph_templates_item ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'id');
        }
        $GLOBALS['nativeChildCoverageMarkers'] = array('native-policy-operation-returned','policy-session-observed','cascade-controller-state-observed');
        print json_encode(array('result' => $state, 'session' => $_SESSION), JSON_THROW_ON_ERROR);
    });
    $controller = ($scenario['resource'] ?? 'graph') === 'graph' ? 'graphs.php' : 'data_sources.php';
    ob_start();
    $GLOBALS['removalFixtureNativeQueries'] = 0;
    $source = file_get_contents(getenv('GRAPH_DATA_REMOVAL_CONTROLLER_SOURCE') ?: $root . '/' . $controller);
    if ($source === false) throw new RuntimeException('Cannot read native cascade controller.');
    foreach (($scenario['resource'] ?? 'graph') === 'graph' ? array('graph_edit_graph_is_allowed','graph_edit_access_denied','form_actions') : array('data_source_device_is_allowed','data_source_access_denied','form_actions') as $function) {
        eval(test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
    form_actions();
    exit;
}

function graph_data_removal_fixture_execute(string $sql, array $params, mixed $connection): bool
{
    $GLOBALS['removalFixtureWrites'][] = array('sql' => $sql, 'remote' => $connection !== false);
    $failure = $GLOBALS['scenario']['write_failure'] ?? '';
    if ($failure !== '' && str_contains($sql, $failure) && $connection === false) {
        $GLOBALS['database_last_error'] = 'fixture write failure';
        return false;
    }
    $remote_writes = count(array_filter($GLOBALS['removalFixtureWrites'], static fn($write) => $write['remote']));
    if ($connection !== false && (($GLOBALS['scenario']['remote_write_failure'] ?? false) || $remote_writes === ($GLOBALS['scenario']['remote_write_failure_at'] ?? 0))) {
        $GLOBALS['database_last_error'] = 'fixture remote write failure';
        return false;
    }
    $query = ($connection ?: $GLOBALS['db'])->prepare($sql);
    return $query->execute($params);
}

function db_execute($sql, $log = true, $connection = false)
{
    return db_execute_prepared($sql, array(), $log, $connection);
}
function get_request_var($name)
{
    return $_POST[$name] ?? '';
}
function get_nfilter_request_var($name)
{
    return get_request_var($name);
}
function get_filter_request_var($name, ...$args)
{
    return get_request_var($name);
}
function isset_request_var($name)
{
    return array_key_exists($name, $_POST);
}
function set_request_var($name, $value)
{
    $_POST[$name] = $value;
}
function sanitize_unserialize_selected_items($value)
{
    return unserialize($value, array('allowed_classes' => false));
}
function array_to_sql_or($ids, $column)
{
    return $column . ' IN (' . implode(',', $ids) . ')';
}
function cacti_count($value)
{
    return is_countable($value) ? count($value) : 0;
}
function api_plugin_hook_function($hook, $args)
{
    $GLOBALS['removalFixtureHooks'][] = array($hook, $args);
    if ($hook === ($GLOBALS['scenario']['revoke_hook'] ?? null)) {
        $GLOBALS['db']->exec('DELETE FROM user_auth_perms WHERE user_id=42 AND type=3 AND item_id=100');
    }
    if ($hook === ($GLOBALS['scenario']['link_hook'] ?? null)) {
        $GLOBALS['db']->exec('INSERT INTO graph_templates_item(id,local_graph_id,task_item_id) VALUES(699,201,500)');
    }
    if ($hook === ($GLOBALS['scenario']['owner_hook'] ?? null)) {
        $GLOBALS['db']->exec('UPDATE data_local SET host_id=101 WHERE id=300');
    }
    return $args;
}
function raise_message($code, ...$args)
{
    $GLOBALS['removalFixtureMessages'][] = array($code, $args[0] ?? '');
}
function __($text, ...$args)
{
    return $args ? sprintf($text, ...$args) : $text;
}
function __n($singular, $plural, $count)
{
    return $count === 1 ? $singular : $plural;
}
function __esc($text)
{
    return htmlspecialchars($text, ENT_QUOTES);
}
function html_escape($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}
function input_validate_input_number($value)
{
    if (auth_resource_id($value) === null) throw new RuntimeException('Bad input');
}
function get_graph_title($id)
{
    $GLOBALS['removalFixtureReads'][] = 'graph-title:' . $id;
    return db_fetch_cell_prepared('SELECT title_cache FROM graph_templates_graph WHERE local_graph_id=?', array($id));
}
function get_data_source_title($id)
{
    $GLOBALS['removalFixtureReads'][] = 'source-title:' . $id;
    return db_fetch_cell_prepared('SELECT name_cache FROM data_template_data WHERE local_data_id=?', array($id));
}
function set_config_option($name, $value)
{
    return db_execute_prepared('INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', array($name, $value));
}
function snmpagent_graphs_action_bottom($args)
{
    $GLOBALS['removalFixtureHooks'][] = array('snmp-graphs', $args);
}
function snmpagent_data_source_action_bottom($args)
{
    $GLOBALS['removalFixtureHooks'][] = array('snmp-data', $args);
}
function poller_push_to_remote_db_connect($id, $is_poller = false)
{
    $GLOBALS['removalFixtureRemoteCalls'][] = array($id, $is_poller);
    if ($GLOBALS['scenario']['remote_connect_failure'] ?? false) return false;
    return $GLOBALS['removalFixtureRemote'] ??= $GLOBALS['db'];
}
function top_header() {}
function form_start(...$args) {}
function html_start_box(...$args) {}
function html_end_box(...$args) {}
function escape_page_action(...$args)
{
    return '';
}
function add_tree_names_to_actions_array() {}
function form_hidden_box(...$args) {}
function form_save_button(...$args) {}
function form_end() {}
function bottom_footer() {}
