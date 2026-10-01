<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Real controller, validator, HTML and SQL. Bootstrap, permission dropdown
// visibility, translation and total-count caching are isolated boundaries;
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
$_SESSION = array('sess_user_id' => 99, 'sentinel' => 'preserved');
$config = array('base_path' => $root, 'poller_id' => 1, 'connection' => 'online', 'url_path' => '/', 'is_web' => false, 'config_options_array' => array('num_rows_table' => 2, 'selected_theme' => 'classic', 'autocomplete_enabled' => ''));
$themes = array('classic' => 'Classic');
$item_rows = array(1 => 'One', 2 => 'Two & more', 10 => 'Ten');
$auth_realms = array(0 => 'Local & trusted');
$poller_actions = array(0 => 'SNMP', 1 => 'Script', 2 => 'Script Server');
$_REQUEST = array_merge(array('action' => 'native_fixture'), $scenario['request']);
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec("CREATE TABLE settings_user(user_id INTEGER, name TEXT, value TEXT);
INSERT INTO settings_user VALUES(99, 'selected_theme', 'classic');
CREATE TABLE user_auth(id INTEGER, username TEXT, full_name TEXT, realm INTEGER);
CREATE TABLE user_log(username TEXT, time TEXT, result INTEGER, ip TEXT);
INSERT INTO user_auth VALUES(1, 'Alpha & <script>', 'A & <script>', 0),(2, 'Beta', 'B', 9);
INSERT INTO user_log VALUES('Alpha & <script>', '2026-09-01', 0, '192.0.2.1'),('Alpha & <script>', '2026-09-02', 1, '192.0.2.2'),('Beta', '2026-09-03', 2, '192.0.2.3'),('Removed', '2026-09-04', 3, '192.0.2.4');
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
$tables = array('user_auth', 'user_log', 'host', 'snmp_query', 'host_snmp_cache', 'data_template', 'data_local', 'data_template_data', 'poller_item', 'settings_user');
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
    return db_fetch_cell_prepared($sql, $params);
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
function number_format_i18n($number, $decimals)
{
    return number_format($number, $decimals);
}
final class CactiSecureHeaders
{
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
require $root . '/utilities.php';
ob_start();
match ($scenario['view']) {
    'user' => utilities_view_user_log(),
    'snmp' => utilities_view_snmp_cache(),
    'poller' => utilities_view_poller_cache(),
};
$html = ob_get_clean();
$after = array();
foreach ($tables as $table) {
    $after[$table] = $db->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode(array('before' => $before, 'after' => $after, 'html' => $html, 'queries' => $queries, 'request' => $_REQUEST, 'session' => $_SESSION), JSON_THROW_ON_ERROR);
