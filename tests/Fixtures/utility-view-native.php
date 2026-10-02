<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Real controller, validator, HTML and SQL. Bootstrap, permission dropdown
// visibility, translation, CSRF tokens and total-count caching are isolated boundaries;
// this fixture does not exercise HTTP authentication or authorization.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
mkdir($directory . '/include', 0700, true);
file_put_contents($directory . '/include/auth.php', '<?php');
symlink($root . '/lib', $directory . '/lib');
chdir($directory);
$_SERVER['PHP_SELF'] = 'utilities.php';
$_SERVER['SCRIPT_NAME'] = 'utilities.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
session_start();
$_SESSION = array('sess_user_id' => 99, 'sentinel' => 'preserved');
$config = array('base_path' => $root, 'poller_id' => 1, 'connection' => 'online', 'url_path' => '/', 'is_web' => false, 'cacti_version' => 'native', 'cacti_server_os' => 'unix', 'config_options_array' => array('num_rows_table' => 2, 'selected_theme' => 'classic', 'autocomplete_enabled' => '', 'path_cactilog' => $directory . '/cacti.log', 'path_stderrlog' => $directory . '/stderr.log', 'max_display_rows' => 2, 'log_refresh_interval' => 300, 'guest_user' => 0, 'auth_method' => 0));
$no_session_write = array('utilities.php');
$messages = array();
$themes = array('classic' => 'Classic');
$item_rows = $scenario['choices'] ?? array(1 => 'One', 2 => 'Two & more', 10 => 'Ten');
$auth_realms = array(0 => 'Local & trusted');
$poller_actions = array(0 => 'SNMP', 1 => 'Script', 2 => 'Script Server');
$_REQUEST = array_merge(array('action' => 'native_fixture', 'header' => 'false'), $scenario['request']);
$log_tail_lines = array(-1 => 'Default', 2 => 'Two', 10 => 'Ten');
$page_refresh_interval = array(60 => 'One minute', 300 => 'Five minutes');
file_put_contents($directory . '/cacti.log', "STATS Device[1] DS[101] Alpha & <script>\nWARN Beta\nERROR Gamma\nDEBUG Delta\nPlain Fifth\n");
file_put_contents($directory . '/cacti.log-20260930', "WARN Archived\n");
file_put_contents($directory . '/stderr.log', "ERROR Stderr\n");
$logBefore = hash_file('sha256', $directory . '/cacti.log');
$boost = $scenario['view'] === 'boost';
if ($boost) {
    $db = new PDO(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false));
    $ownedDatabase = 'utility_boost_' . bin2hex(random_bytes(8));
    $db->exec('CREATE DATABASE `' . $ownedDatabase . '`');
    $db->exec('USE `' . $ownedDatabase . '`');
    register_shutdown_function(static function () use ($db, $ownedDatabase) {
        $db->exec('DROP DATABASE `' . $ownedDatabase . '`');
    });
    $database_hostname = 'native-fixture';
    $database_port = 3306;
    $database_default = $ownedDatabase;
    $database_sessions = array('native-fixture:3306:' . $ownedDatabase => $db);
} else {
    $db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
}
$db->exec("CREATE TABLE settings_user(user_id INTEGER, name TEXT, value TEXT);
INSERT INTO settings_user VALUES(99, 'selected_theme', 'classic');
CREATE TABLE user_auth(id INTEGER, username TEXT, full_name TEXT, realm INTEGER);
CREATE TABLE user_log(user_id INTEGER, username TEXT, time TEXT, result INTEGER, ip TEXT);
INSERT INTO user_auth VALUES(1, 'Alpha & <script>', 'A & <script>', 0),(2, 'Beta', 'B', 9);
INSERT INTO user_log VALUES(1, 'Alpha & <script>', '2026-09-01', 0, '192.0.2.1'),(1, 'Alpha & <script>', '2026-09-02', 1, '192.0.2.2'),(2, 'Beta', '2026-09-03', 2, '192.0.2.3'),(99, 'Removed', '2026-09-04', 3, '192.0.2.4');
CREATE TABLE host(id INTEGER, description TEXT, disabled TEXT);
CREATE TABLE snmp_query(id INTEGER, name TEXT);
CREATE TABLE host_snmp_cache(host_id INTEGER, snmp_query_id INTEGER, snmp_index TEXT, field_name TEXT, field_value TEXT, oid TEXT);
INSERT INTO host VALUES(1, 'Alpha & <script>', ''),(2, 'Beta', 'on');
INSERT INTO snmp_query VALUES(10, 'Query & <script>'),(20, 'Other query');
INSERT INTO host_snmp_cache VALUES(1,10,'index-only','Field & <script>','Value & <script>','OID-alpha'),(1,10,'second','Other','Second','OID-second'),(2,20,'foreign','Other','Foreign','OID-foreign');
CREATE TABLE data_template(id INTEGER, name TEXT);
CREATE TABLE data_local(id INTEGER, host_id INTEGER, data_template_id INTEGER);
CREATE TABLE data_template_data(local_data_id INTEGER, data_template_id INTEGER, name_cache TEXT, active TEXT);
CREATE TABLE poller_item(local_data_id INTEGER, host_id INTEGER, action INTEGER, hostname TEXT, arg1 TEXT, rrd_path TEXT, snmp_version INTEGER, snmp_community TEXT, snmp_username TEXT);
INSERT INTO data_template VALUES(10,'Template & <script>'),(20,'Other template');
INSERT INTO data_local VALUES(101,1,10),(102,1,10),(103,2,20),(104,2,0);
INSERT INTO data_template_data VALUES(101,10,'Alpha DS & <script>','on'),(102,10,'Beta DS','on'),(103,20,'Gamma DS',''),(104,0,'Delta DS','on');
INSERT INTO poller_item VALUES(101,1,0,'alpha','OID & <script>','/a & <script>.rrd',2,'public & <script>',''),(102,1,0,'alpha','OID-v3','/b.rrd',3,'','v3 & <script>'),(103,2,1,'beta','script & <script>','/c.rrd',0,'',''),(104,2,2,'beta','server & <script>','/d.rrd',0,'','');");
if ($scenario['same_name_realms'] ?? false) {
    $db->exec("INSERT INTO user_auth VALUES(3, 'Shared Name', 'Original Account', 0),(4, 'Shared Name', 'Foreign Account', 9);
INSERT INTO user_log VALUES(3, 'Shared Name', '2026-09-05', 1, '192.0.2.5'),(3, 'Shared Name', '2026-09-06', 1, '192.0.2.6'),(4, 'Shared Name', '2026-09-07', 1, '192.0.2.7');");
}
if ($scenario['mismatched_log_principals'] ?? false) {
    $db->exec("INSERT INTO user_log VALUES(1, 'Shared Name', '2026-09-08', 1, '192.0.2.8'),(2, 'Alpha & <script>', '2026-09-09', 2, '192.0.2.9'),(1, 'Beta', '2026-09-10', 0, '192.0.2.10');");
}
$db->exec("CREATE TABLE snmpagent_cache(oid TEXT, name TEXT, mib TEXT, `max-access` TEXT, kind TEXT, value TEXT, description TEXT);
CREATE TABLE snmpagent_managers(id INTEGER, hostname TEXT);
CREATE TABLE snmpagent_notifications_log(id INTEGER, manager_id INTEGER, notification TEXT, severity INTEGER, time INTEGER, varbinds TEXT);
INSERT INTO snmpagent_cache VALUES('1.1','Name & <script>','MIB-A','read-only','Scalar','Value & <script>','Description & <script>'),('1.2','Other','MIB-A','read-write','Column Data','Second',''),('1.3','Foreign','MIB-B','not-accessible','Table','unused','');
INSERT INTO snmpagent_managers VALUES(1,'Receiver & <script>'),(2,'Foreign receiver');
INSERT INTO snmpagent_notifications_log VALUES(1,1,'Name & <script>',1,100,'Bind & <script>'),(2,1,'Other',3,200,'Second'),(3,2,'Foreign',4,300,'Foreign');");
$tables = array('user_auth', 'user_log', 'host', 'snmp_query', 'host_snmp_cache', 'data_template', 'data_local', 'data_template_data', 'poller_item', 'settings_user', 'snmpagent_cache', 'snmpagent_managers', 'snmpagent_notifications_log');
if ($boost) {
    $db->exec("CREATE TABLE settings(name VARCHAR(100) PRIMARY KEY, value TEXT);
CREATE TABLE poller_output_boost(local_data_id INTEGER, output VARCHAR(100)) ENGINE=InnoDB;
CREATE TABLE poller_output_boost_local_data_ids(local_data_id INTEGER, process_handler INTEGER);
CREATE TABLE processes(tasktype VARCHAR(20), taskname VARCHAR(20), taskid INTEGER, started DATETIME);
INSERT INTO poller_output_boost VALUES(101,'one'),(102,'two');
INSERT INTO poller_output_boost_local_data_ids VALUES(101,1);
INSERT INTO processes VALUES('boost','child',1,NOW()),('other','child',2,NOW());");
    mkdir($directory . '/images');
    file_put_contents($directory . '/images/one.png', 'abc');
    file_put_contents($directory . '/images/two.JPG', 'defgh');
    file_put_contents($directory . '/images/ignored.txt', 'not an image');
    $boostSettings = array('boost_last_run_time' => 1700000000, 'boost_next_run_time' => 1700000300, 'boost_last_end_time' => 1700000090, 'boost_rrd_update_enable' => 'on', 'boost_png_cache_enable' => 'on', 'boost_rrd_update_max_records' => 1000, 'boost_rrd_update_max_runtime' => 60, 'boost_rrd_update_interval' => 5, 'boost_peak_memory' => 2097152, 'stats_detail_boost' => 'Rows:10 Time:2 GetRows:3 ResultsCycle:4 FileAndTemplate:5 LastUpdate:6 RRDUpdate:7 Delete:8', 'boost_poller_status' => $scenario['status'], 'stats_boost' => 'Runtime:90 RRDs:2', 'boost_png_cache_directory' => $directory . '/images', 'boost_max_output_length' => time() . ':8', 'boost_poller_mem_limit' => -1, 'boost_parallel' => 2, 'stats_boost_1' => 'Runtime:7 Other:0 RRDs:9', 'stats_boost_2' => '');
    if (!empty($scenario['empty'])) {
        $boostSettings['stats_detail_boost'] = '';
        $boostSettings['stats_boost'] = '';
        $boostSettings['boost_png_cache_directory'] = $directory . '/missing';
        $boostSettings['boost_png_cache_enable'] = '';
    }
    $insertSetting = $db->prepare('INSERT INTO settings VALUES(?,?)');
    foreach ($boostSettings as $name => $value) {
        $insertSetting->execute(array($name, $value));
    }
    $boost_utilities_interval = array(30 => 'Thirty seconds', 60 => 'One minute');
    $boost_refresh_interval = array(5 => 'Five minutes');
    $boost_max_runtime = array(60 => 'One hour');
    $tables = array_merge($tables, array('settings', 'poller_output_boost', 'poller_output_boost_local_data_ids', 'processes'));
}
$before = array();
foreach ($tables as $table) {
    $before[$table] = $db->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
}
$queries = array();
function db_fetch_assoc_prepared($sql, $params = array())
{
    $GLOBALS['queries'][] = array($sql, $params);
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_row_prepared($sql, $params = array())
{
    return db_fetch_assoc_prepared($sql, $params)[0] ?? array();
}
function db_table_exists($table)
{
    return (bool) db_fetch_cell_prepared('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=SCHEMA() AND TABLE_NAME=?', array($table));
}
function db_execute($sql)
{
    $GLOBALS['queries'][] = array($sql, array());
    return $GLOBALS['db']->exec($sql) !== false;
}
function db_fetch_assoc($sql)
{
    return db_fetch_assoc_prepared($sql);
}
function db_fetch_cell_prepared($sql, $params = array())
{
    $GLOBALS['queries'][] = array($sql, $params);
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetchColumn();
}
function db_fetch_cell($sql)
{
    return db_fetch_cell_prepared($sql);
}
function db_qstr($value)
{
    return $GLOBALS['db']->quote($value);
}
function get_total_row_data($user, $sql, $params)
{
    $GLOBALS['total_rows'][] = (int) db_fetch_cell_prepared($sql, $params);
    return end($GLOBALS['total_rows']);
}
function get_allowed_devices($where)
{
    return db_fetch_assoc('SELECT * FROM host ORDER BY description');
}
function __($text, ...$args)
{
    return $args ? vsprintf($text, $args) : $text;
}
function __esc($text, ...$args)
{
    return html_escape(__($text, ...$args));
}
function __x($context, $text)
{
    return $text;
}
function __esc_x($context, $text)
{
    return html_escape($text);
}
function api_plugin_hook_function($hook, $value)
{
    return true;
}
function api_plugin_hook($hook) {}
function api_plugin_is_enabled($name)
{
    return false;
}
function db_close() {}
function csrf_get_tokens()
{
    return 'isolated-csrf-boundary';
}
function number_format_i18n($number, $decimals = 0)
{
    return number_format($number, $decimals);
}
final class CactiSecureHeaders
{
    public static function getNonce()
    {
        return 'utility-fixture';
    }
    public static function getNonceAttribute()
    {
        return "nonce='utility-fixture'";
    }
}
define('VALID_HOST_FIELDS', '(hostname)');
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/html.php';
require $root . '/lib/html_utility.php';
require $root . '/lib/html_form.php';
require $root . '/lib/variables.php';
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('UTILITY_VIEW_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/src/Platform/Infrastructure/Legacy/UtilityRows.php';
require $root . '/utilities.php';
ob_start();
match ($scenario['view']) {
    'user' => utilities_view_user_log(),
    'snmp' => utilities_view_snmp_cache(),
    'poller' => utilities_view_poller_cache(),
    'agent' => snmpagent_utilities_run_cache(),
    'event' => snmpagent_utilities_run_eventlog(),
    'log' => utilities_view_logfile(),
    'boost' => boost_display_run_status(),
    'options' => \Kadupul\Platform\Infrastructure\Legacy\UtilityRows::renderOptions($scenario['choices'], $scenario['selected']),
};
$html = ob_get_clean();
define('NATIVE_COVERAGE_COMPLETED', array('utility-view-observed:' . $scenario['view']));
$after = array();
foreach ($tables as $table) {
    $after[$table] = $db->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
}
// This CLI-only fixture emits a JSON protocol, with HTML characters escaped.
fwrite(STDOUT, json_encode(array('total_rows' => $GLOBALS['total_rows'] ?? array(), 'log_before' => $logBefore, 'log_after' => hash_file('sha256', $directory . '/cacti.log'), 'before' => $before, 'after' => $after, 'html' => $html, 'queries' => $queries, 'request' => $_REQUEST, 'session' => $_SESSION), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
