<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$directory = $argv[2];
$scenario = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
mkdir($directory . '/include');
mkdir($directory . '/lib');
mkdir($directory . '/include/themes/classic', 0700, true);
file_put_contents($directory . '/include/themes/classic/main.css', '');
foreach (array('include/auth.php', 'include/global_session.php', 'include/top_header.php', 'include/bottom_footer.php', 'lib/poller.php', 'lib/utility.php') as $stub) {
    file_put_contents($directory . '/' . $stub, '<?php');
}
copy($root . '/lib/api_automation.php', $directory . '/api_automation.php');
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $directory . '/api_automation.php');
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/lib/api_automation.php');
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/include/vendor/ezyang/htmlpurifier/library/HTMLPurifier.auto.php';
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('UNIX_TIMESTAMP', static fn($value) => strtotime($value));
$db->sqliteCreateFunction('FROM_UNIXTIME', static fn($value) => date('Y-m-d H:i:s', $value));
$definitions = array(
    'graph_tree' => 'id INTEGER PRIMARY KEY, sort_type INTEGER',
    'graph_tree_items' => 'id INTEGER PRIMARY KEY, graph_tree_id INTEGER, title TEXT, parent INTEGER, local_graph_id INTEGER DEFAULT 0, host_id INTEGER DEFAULT 0, site_id INTEGER DEFAULT 0, host_grouping_type INTEGER DEFAULT 0, sort_children_type INTEGER DEFAULT 1, position INTEGER DEFAULT 1',
    'sites' => 'id INTEGER PRIMARY KEY, name TEXT',
    'host' => 'id INTEGER PRIMARY KEY, hostname TEXT, description TEXT, disabled TEXT, status INTEGER, host_template_id INTEGER, deleted TEXT',
    'host_template' => 'id INTEGER PRIMARY KEY, name TEXT',
    'graph_local' => 'id INTEGER PRIMARY KEY, host_id INTEGER, graph_template_id INTEGER, snmp_query_graph_id INTEGER, snmp_query_id INTEGER, snmp_index TEXT',
    'graph_templates' => 'id INTEGER PRIMARY KEY, name TEXT, test_source TEXT',
    'graph_templates_graph' => 'local_graph_id INTEGER, graph_template_id INTEGER, title_cache TEXT, t_title TEXT, title TEXT, id INTEGER, height INTEGER, width INTEGER',
    'data_template_data' => 'id INTEGER PRIMARY KEY, data_template_id INTEGER, local_data_id INTEGER, t_name TEXT, name TEXT',
    'data_template_rrd' => 'id INTEGER PRIMARY KEY, data_template_id INTEGER, local_data_id INTEGER',
    'graph_templates_item' => 'id INTEGER PRIMARY KEY, graph_template_id INTEGER, task_item_id INTEGER, hash TEXT, local_graph_id INTEGER',
    'data_template' => 'id INTEGER PRIMARY KEY, hash TEXT',
    'data_input_data' => 'data_template_data_id INTEGER, data_input_field_id INTEGER, t_value TEXT, value TEXT',
    'data_input_fields' => 'id INTEGER PRIMARY KEY, data_input_id INTEGER, input_output TEXT, type_code TEXT, allow_nulls TEXT',
    'automation_tree_rule_items' => 'id INTEGER PRIMARY KEY, rule_id INTEGER, field TEXT, search_pattern TEXT, replace_pattern TEXT, sequence INTEGER',
    'data_local' => 'id INTEGER PRIMARY KEY, host_id INTEGER',
    'snmp_query' => 'id INTEGER PRIMARY KEY, name TEXT, xml_path TEXT',
    'snmp_query_graph' => 'id INTEGER PRIMARY KEY, snmp_query_id INTEGER, graph_template_id INTEGER',
    'host_snmp_cache' => 'host_id INTEGER, snmp_query_id INTEGER, snmp_index TEXT, field_name TEXT, field_value TEXT',
    'automation_graph_rules' => 'id INTEGER PRIMARY KEY, name TEXT, snmp_query_id INTEGER, graph_type_id INTEGER',
    'automation_graph_rule_items' => 'id INTEGER PRIMARY KEY, rule_id INTEGER, sequence INTEGER, operation INTEGER, field TEXT, operator INTEGER, pattern TEXT',
    'automation_devices' => 'id INTEGER PRIMARY KEY, ip TEXT',
    'automation_snmp_items' => 'id INTEGER PRIMARY KEY, snmp_id INTEGER, sequence INTEGER, snmp_version INTEGER, snmp_port INTEGER, snmp_timeout INTEGER, snmp_retries INTEGER, snmp_community TEXT, snmp_username TEXT, snmp_password TEXT, snmp_auth_protocol TEXT, snmp_priv_passphrase TEXT, snmp_priv_protocol TEXT, snmp_context TEXT, snmp_engine_id TEXT, max_oids INTEGER, bulk_walk_size INTEGER',
    'automation_networks' => 'id INTEGER PRIMARY KEY, sched_type INTEGER, recur_every INTEGER, start_at TEXT, next_start TEXT, day_of_week TEXT, month TEXT, day_of_month TEXT, monthly_week TEXT, monthly_day TEXT',
    'automation_tree_rules' => 'id INTEGER PRIMARY KEY, leaf_type INTEGER, name TEXT',
    'automation_match_rule_items' => 'id INTEGER PRIMARY KEY, rule_id INTEGER, rule_type INTEGER, sequence INTEGER, operation INTEGER, field TEXT, operator INTEGER, pattern TEXT',
    'settings' => 'name TEXT PRIMARY KEY, value TEXT',
    'settings_user' => 'name TEXT,user_id INTEGER,value TEXT',
    'user_auth' => 'id INTEGER PRIMARY KEY,username TEXT,reset_perms INTEGER, policy_hosts INTEGER, policy_graphs INTEGER, policy_graph_templates INTEGER, policy_trees INTEGER',
    'user_auth_perms' => 'user_id INTEGER, type INTEGER, item_id INTEGER',
    'user_auth_group' => 'id INTEGER, name TEXT, enabled TEXT, policy_hosts INTEGER, policy_graphs INTEGER, policy_graph_templates INTEGER, policy_trees INTEGER',
    'user_auth_group_members' => 'user_id INTEGER, group_id INTEGER',
    'user_auth_row_cache' => 'user_id INTEGER, class TEXT, hash TEXT, total_rows INTEGER, time TEXT',
);
foreach ($definitions as $table => $columns) {
    $db->exec('CREATE TABLE ' . $table . ' (' . $columns . ')');
}
$db->exec("INSERT INTO user_auth VALUES (7,'fixture-admin',0,1,1,1,1)");
$db->exec('INSERT INTO graph_tree VALUES (8,1),(9,1)');
$db->exec("INSERT INTO graph_tree_items (id,graph_tree_id,parent,title) VALUES (77,8,0,'Parent'),(88,9,0,'Unrelated')");
$db->exec("INSERT INTO host_template VALUES (9,'Fixture template')");
$db->exec("INSERT INTO host_template VALUES (10,'|host_description| <b>routers</b>')");
$stmt = $db->prepare("INSERT INTO host VALUES (7,'127.0.0.1',?,'',3,9,'')");
$stmt->execute(array(($scenario['target'] ?? 'host')));
$calls = array();
function automation_native_statement($sql, $params = array())
{
    $GLOBALS['calls'][] = array($sql, $params);
    // SQLite spells MySQL's null-safe equality operator IS.
    $sql = str_replace('<=>', 'IS', $sql);
    if (str_contains($sql, 'ON DUPLICATE KEY UPDATE') && str_contains($sql, 'INSERT INTO settings')) {
        $sql = 'INSERT OR REPLACE INTO settings (name,value) VALUES (?,?)';
    }
    if (preg_match('/^SHOW COLUMNS FROM (\w+)$/', $sql, $columns)) {
        $sql = "SELECT name AS Field, type AS Type FROM pragma_table_info('" . $columns[1] . "')";
    }
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function db_fetch_cell_prepared($sql, $params = array())
{
    return automation_native_statement($sql, $params)->fetchColumn();
}
function db_fetch_cell($sql)
{
    return db_fetch_cell_prepared($sql);
}
function db_fetch_row_prepared($sql, $params = array())
{
    return automation_native_statement($sql, $params)->fetch(PDO::FETCH_ASSOC) ?: array();
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    return automation_native_statement($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_assoc($sql)
{
    return db_fetch_assoc_prepared($sql);
}
function sql_save($values, $table)
{
    if (empty($values['id'])) {
        unset($values['id']);
    }
    $columns = array_keys($values);
    automation_native_statement('INSERT INTO ' . $table . ' (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')', array_values($values));
    return (int) $GLOBALS['db']->lastInsertId();
}
function db_qstr($value)
{
    return $GLOBALS['db']->quote($value);
}
function db_column_exists($table, $column)
{
    return in_array($column, array_column($GLOBALS['db']->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
}
function db_execute_prepared($sql, $params = array())
{
    automation_native_statement($sql, $params);
    return true;
}
function db_execute($sql)
{
    automation_native_statement($sql);
    return true;
}
function db_close() {}
function db_table_exists($table)
{
    return isset($GLOBALS['definitions'][$table]);
}
function api_plugin_hook($name, ...$arguments) {}
function api_plugin_hook_function($name, $value)
{
    return $value;
}
function number_format_i18n($number, $decimals = null, $baseu = 1024)
{
    return number_format($number, $decimals ?? 2, '.', ',');
}
function __esc($text, ...$values)
{
    return html_escape(__($text, ...$values));
}
function csrf_check($fatal = true)
{
    return true;
}
function get_installed_locales()
{
    return array('en-US' => 'English');
}
function get_new_user_default_language()
{
    return 'en-US';
}
function csrf_check_valid()
{
    return true;
}
function __($text, ...$values)
{
    return $values ? sprintf($text, ...$values) : $text;
}
function __x($context, $text, ...$values)
{
    return __($text, ...$values);
}
function __n($single, $plural, $count)
{
    return $count === 1 ? $single : $plural;
}
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/html.php';
require $root . '/lib/html_validate.php';
require $root . '/lib/html_form.php';
require $root . '/lib/headers_secure.php';
require $root . '/lib/variables.php';
session_save_path($directory);
session_start();
$_SESSION = array('sess_user_id' => 7, 'selected_theme' => 'classic', 'sess_user_perms_key' => 0, 'sess_user_realms' => array_fill_keys(range(1, 1000), true), 'sess_config_array' => array('log_destination' => 1, 'path_cactilog' => $directory . '/native.log', 'log_validation' => '', 'selective_debug' => '', 'selective_plugin_debug' => ''));
$_SERVER['SCRIPT_NAME'] = '/api_automation.php';
$_SERVER['REQUEST_URI'] = '/api_automation.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST = $_POST = array('id' => 8, 'header' => 'false', 'rows' => 10, 'page' => 1, 'host_status' => -1, 'host_template_id' => -1, 'sort_column' => 'description', 'sort_direction' => 'ASC', 'filter' => '');
$_REQUEST['rowsd'] = 20;
$_REQUEST['paged'] = 1;
$_REQUEST['filterd'] = '';
$_CACTI_REQUEST = array();
$config = array('base_path' => $root, 'cacti_server_os' => 'unix', 'connection' => 'online', 'cacti_db_version' => '1.3.0', 'poller_id' => 1, 'is_web' => false, 'url_path' => '/', 'config_options_array' => array('log_validation' => '', 'selected_theme' => 'classic', 'log_destination' => 1, 'path_cactilog' => $directory . '/native.log', 'selective_debug' => '', 'selective_plugin_debug' => '', 'log_verbosity' => POLLER_VERBOSITY_LOW, 'date' => 'Y-m-d', 'time' => 'H:i:s', 'auth_method' => 1, 'default_graphs_per_page' => 10, 'num_rows_table' => 10));
$no_session_write = array('api_automation.php');
$no_http_header_files = array();
require $root . '/lib/auth.php';
require $root . '/include/global_arrays.php';
require $root . '/include/global_settings.php';
require $root . '/include/global_form.php';
$config['base_path'] = $directory;
ob_start();
register_shutdown_function(function () use ($db, $directory) {
    file_put_contents($directory . '/result.json', json_encode(array('html' => ob_get_clean(), 'nodes' => $db->query('SELECT * FROM graph_tree_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'messages' => $_SESSION['sess_messages'] ?? array(), 'log' => is_file($directory . '/native.log') ? file_get_contents($directory . '/native.log') : '', 'calls' => $GLOBALS['calls'], 'result' => $GLOBALS['result'] ?? null, 'contracts' => $GLOBALS['contracts'] ?? array()), JSON_THROW_ON_ERROR));
});
if ($scenario['mode'] === 'node') {
    // Isolate the downstream tree writer; header handoff modes load the actual API.
    function api_tree_host_exists($tree, $parent, $id)
    {
        return db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id=? AND parent=? AND host_id=?', array($tree,$parent,$id));
    }
    function api_tree_site_exists($tree, $parent, $id)
    {
        return db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id=? AND parent=? AND site_id=?', array($tree,$parent,$id));
    }
    function api_tree_graph_exists($tree, $parent, $id)
    {
        return db_fetch_cell_prepared('SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id=? AND parent=? AND local_graph_id=?', array($tree,$parent,$id));
    }
    function api_tree_item_save(...$args)
    {
        $GLOBALS['contracts']['save'][] = $args;
        if (!empty($GLOBALS['scenario']['reject'])) {
            return 0;
        }
        return sql_save(array('graph_tree_id' => $args[1], 'parent' => $args[3], 'title' => $args[4], 'local_graph_id' => $args[5], 'host_id' => $args[6], 'site_id' => $args[7]), 'graph_tree_items');
    }
} else {
    require $root . '/lib/api_tree.php';
}
// Device creation and SNMP transport are explicit out-of-module boundaries.
function get_data_query_array($id)
{
    if ($GLOBALS['scenario']['mode'] === 'objects') {
        return array();
    }
    return array('fields' => array('ifName' => array('direction' => 'input','name' => 'Interface')));
}
function get_best_data_query_index_type($host, $query)
{
    return 'ifName';
}
function create_complete_graph_from_template($template, $host, $query, &$suggestions)
{
    $GLOBALS['contracts']['created'][] = array($template,$host,$query);
    if ($GLOBALS['scenario']['case'] === 'rejected') {
        return false;
    }
    if ($GLOBALS['scenario']['case'] === 'empty') {
        return array();
    }
    return array('local_graph_id' => 101,'local_data_id' => array(201));
}
function push_out_host($host, $data)
{
    $GLOBALS['contracts']['pushed'][] = array($host,$data);
}
function api_device_save(...$args)
{
    $GLOBALS['contracts']['device_save'] = $args;
    return !empty($GLOBALS['scenario']['reject']) ? 0 : 17;
}
function api_plugin_is_enabled($name)
{
    return false;
}
function cacti_snmp_session(...$args)
{
    $GLOBALS['contracts']['sessions'][] = $args;
    if ($GLOBALS['scenario']['case'] === 'session-failed') {
        return false;
    }
    return new class {
        public function close()
        {
            $GLOBALS['contracts']['closed'] = true;
        }
    };
}
function cacti_snmp_session_get($session, $oid)
{
    if (str_ends_with($oid, '.2.0')) {
        if ($GLOBALS['scenario']['case'] === 'unknown' || (count($GLOBALS['contracts']['sessions']) === 1 && $GLOBALS['scenario']['case'] === 'fallback')) {
            return 'U';
        }
        return 'OID: enterprises.9';
    }
    if (str_ends_with($oid, '.3.0')) {
        return 42;
    }
    return '"Fixture system"';
}
require $directory . '/api_automation.php';
$rule = array('id' => 8, 'tree_id' => 8, 'host_grouping_type' => 1);
$item = array('field' => 'h.description', 'search_pattern' => ($scenario['search'] ?? ''), 'replace_pattern' => ($scenario['replace'] ?? ''), 'propagate_changes' => '', 'sort_type' => 1);
if ($scenario['mode'] === 'matches') {
    $graph = $scenario['kind'] === 'graph';
    $db->exec("INSERT INTO graph_templates VALUES (9,'Fixture graph','')");
    $db->exec("INSERT INTO graph_local VALUES (100,7,9,6,5,'1')");
    $db->exec("INSERT INTO graph_templates_graph VALUES (100,9,'Fixture title','on','Title',100,100,200)");
    $db->exec("INSERT INTO automation_match_rule_items VALUES (1,8,3,1,0,'h.id'," . AUTOMATION_OP_MATCHES . ",'7')");
    set_request_var('sort_column', $graph ? 'title_cache' : 'description');
    $function = $graph ? 'display_matching_graphs' : 'display_matching_hosts';
    $function($rule, AUTOMATION_RULE_TYPE_TREE_MATCH, 'automation_tree_rules.php?action=edit&id=8');
} elseif (in_array($scenario['mode'], array('dq','objects','edit'), true)) {
    $db->exec("INSERT INTO automation_graph_rules VALUES (8,'Fixture rule',5,6)");
    $db->exec("INSERT INTO snmp_query VALUES (5,'Fixture query','fixture.xml')");
    $db->exec('INSERT INTO snmp_query_graph VALUES (6,5,9)');
    $db->exec("INSERT INTO graph_templates VALUES (9,'Fixture graph','')");
    $db->exec("INSERT INTO host_snmp_cache VALUES (7,5,'1','ifName','eth0'),(7,5,'2','ifName','eth1'),(8,5,'3','ifName','unrelated')");
    $graphRule = array('id' => 8,'name' => 'Fixture rule','snmp_query_id' => 5,'graph_type_id' => 6);
    if ($scenario['mode'] === 'dq') {
        $db->exec("INSERT INTO graph_local VALUES (100,7,9,6,5,'1')");
        if ($scenario['case'] === 'missing') {
            $db->exec("INSERT INTO automation_graph_rule_items VALUES (1,8,1,0,'missingField',1,'eth')");
        }
        $GLOBALS['result'] = create_dq_graphs(7, 5, $graphRule);
    } elseif ($scenario['mode'] === 'objects') {
        $db->exec("INSERT INTO automation_match_rule_items VALUES (1,8," . AUTOMATION_RULE_TYPE_GRAPH_MATCH . ",1,0,'h.id'," . AUTOMATION_OP_MATCHES . ",'7')");
        display_new_graphs($graphRule, 'automation_graph_rules.php?action=edit&id=8');
    } else {
        $db->exec("INSERT INTO automation_tree_rules VALUES (8," . $scenario['leaf'] . ",'Fixture tree')");
        global_item_edit(8, 0, $scenario['type']);
    }
} elseif ($scenario['mode'] === 'node') {
    $kind = $scenario['kind'];
    $function = array('host' => 'create_device_node','site' => 'create_site_node','graph' => 'create_graph_node')[$kind];
    $GLOBALS['result'] = $function(7, 77, $rule);
    if (empty($scenario['reject'])) {
        $GLOBALS['contracts']['repeat'] = $function(7, 77, $rule);
    }
} elseif ($scenario['mode'] === 'device') {
    $db->exec("INSERT INTO automation_devices VALUES (1,'192.0.2.7'),(2,'192.0.2.8')");
    $device = array('host_template' => 9,'snmp_sysName' => $scenario['name'],'hostname' => $scenario['hostname'],'ip' => '192.0.2.7','snmp_community' => '','snmp_version' => 2,'snmp_username' => '','snmp_password' => '','snmp_port' => 161,'snmp_auth_protocol' => '','snmp_priv_passphrase' => '','snmp_priv_protocol' => '','snmp_context' => '','snmp_engine_id' => '');
    $defaults = array('default_poller' => 1,'default_site' => 2,'snmp_timeout' => 500,'availability_method' => 2,'ping_method' => 1,'ping_port' => 23,'ping_timeout' => 400,'ping_retries' => 2);
    $config['config_options_array'] = $defaults + $config['config_options_array'];
    $_SESSION['sess_config_array'] = $defaults + $_SESSION['sess_config_array'];
    if (!empty($scenario['overrides'])) {
        $device += array('poller_id' => 3,'site_id' => 4,'snmp_timeout' => 600,'availability_method' => 3,'ping_method' => 2,'ping_port' => 24,'ping_timeout' => 450,'ping_retries' => 3,'notes' => 'Owned fixture','max_oids' => 20,'device_threads' => 2,'external_id' => 'fixture-17','location' => 'Fixture site','bulk_walk_size' => 25);
    }
    $GLOBALS['result'] = automation_add_device($device);
    $GLOBALS['contracts']['queued'] = $db->query('SELECT ip FROM automation_devices ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
} elseif ($scenario['mode'] === 'snmp') {
    $db->exec("INSERT INTO automation_snmp_items VALUES (1,8,1,2,161,500,2,'','','','','','','','',10,25),(2,8,2,3,162,600,3,'','','','','','','','',20,30)");
    if ($scenario['case'] === 'empty') {
        $db->exec('DELETE FROM automation_snmp_items');
    }
    $device = array('snmp_id' => 8,'ip_address' => '192.0.2.7');
    $GLOBALS['result'] = automation_valid_snmp_device($device);
    $GLOBALS['contracts']['device'] = $device;
} elseif ($scenario['mode'] === 'eligible') {
    $case = $scenario['case'];
    $db->exec("INSERT INTO graph_templates_graph VALUES (0,9,'Title','on','Title',0,100,200)");
    $db->exec("INSERT INTO data_template_data VALUES (1,2,0,'on','Data')");
    $db->exec("INSERT INTO data_template_rrd VALUES (4,2,0)");
    $db->exec("INSERT INTO graph_templates_item VALUES (1,9,4,'fixture-hash',0)");
    $db->exec("INSERT INTO data_template VALUES (2,'fixture-hash')");
    $db->exec("INSERT INTO data_input_fields VALUES (6,7,'in','','')");
    $db->exec("INSERT INTO data_input_data VALUES (1,6,'on','provided')");
    if ($case === 'graph') {
        $db->exec("UPDATE graph_templates_graph SET title=''");
    } elseif ($case === 'data') {
        $db->exec("UPDATE data_template_data SET name=''");
    } elseif ($case === 'input') {
        $db->exec("UPDATE data_input_data SET value=''");
    } elseif ($case === 'optional') {
        $db->exec("UPDATE data_input_data SET value=''");
        $db->exec("UPDATE data_input_fields SET allow_nulls='on'");
    }
    $GLOBALS['result'] = automation_graph_automation_eligible(9);
} elseif ($scenario['mode'] === 'leaf') {
    $db->exec("INSERT INTO automation_tree_rules VALUES (8,2,'Selected'),(9,2,'Unrelated')");
    $db->exec("INSERT INTO automation_tree_rule_items VALUES (1,8,'gtg.title_cache','x','y',1),(2,8,'gt.name','x','y',2),(3,8,'h.description','x','y',3),(4,9,'gt.name','x','y',1)");
    $db->exec("INSERT INTO automation_match_rule_items VALUES (1,8,1,1,0,'gtg.title_cache',1,'x'),(2,8,1,2,0,'gt.name',1,'x'),(3,8,1,3,0,'h.description',1,'x'),(4,9,1,1,0,'gt.name',1,'x')");
    automation_change_tree_rule_leaf_type($scenario['leaf'], 8);
    automation_change_tree_rule_leaf_type($scenario['leaf'], 8);
    $GLOBALS['contracts'] = array('rules' => $db->query('SELECT * FROM automation_tree_rules ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'items' => $db->query('SELECT id FROM automation_tree_rule_items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'matches' => $db->query('SELECT id FROM automation_match_rule_items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
} elseif ($scenario['mode'] === 'schedule') {
    date_default_timezone_set('UTC');
    $start = date('Y-m-d H:i:s', time() + ($scenario['future'] ? 86400 * 40 : -86400 * 2));
    $stmt = $db->prepare('INSERT INTO automation_networks VALUES (8,?,1,?, ?,?,?,?,?,?)');
    $stmt->execute(array($scenario['type'], $start, $scenario['next'] ? $start : '0000-00-00 00:00:00', '1,2,3,4,5,6,7', '1,2,3,4,5,6,7,8,9,10,11,12', '1,15,32', '1,2,3,4', '1,2,3,4,5,6,7'));
    $GLOBALS['result'] = api_automation_is_time_to_start(8);
    $GLOBALS['contracts'] = $db->query('SELECT * FROM automation_networks WHERE id=8')->fetch(PDO::FETCH_ASSOC);
} elseif ($scenario['mode'] === 'preview') {
    $db->exec('INSERT INTO automation_tree_rules VALUES (8,' . TREE_ITEM_TYPE_HOST . ',"Selected")');
    $db->exec("INSERT INTO automation_match_rule_items VALUES (1,8," . AUTOMATION_RULE_TYPE_TREE_MATCH . ",1,0,'h.id'," . AUTOMATION_OP_MATCHES . ",'7')");
    display_matching_trees(8, AUTOMATION_RULE_TYPE_TREE_MATCH, $item, 'automation_tree_rules.php?action=item_edit&id=8');
} elseif ($scenario['mode'] === 'handoff') {
    $GLOBALS['result'] = create_multi_header_node($scenario['target'], $rule, $item, 77);
    if (!empty($scenario['repeat'])) {
        $GLOBALS['result'] = create_multi_header_node($scenario['target'], $rule, $item, 77);
    }
} else {
    $GLOBALS['result'] = automation_string_replace($scenario['search'], $scenario['replace'], $scenario['target']);
}
