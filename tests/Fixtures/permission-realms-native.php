<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$group = $argv[1] === 'group';
if (isset($argv[3])) {
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/permission-realms-native.php', $argv[1], array('user_admin.php', 'user_group_admin.php', 'lib/html.php', 'lib/html_form.php', 'lib/auth.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionRealms.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'));
}
$directory = $argv[2];
mkdir($directory . '/include', 0700, true);
file_put_contents($directory . '/include/auth.php', '<?php');
chdir($directory);
$request = array('action' => 'fixture', 'id' => 42);
$_SESSION = array('sess_user_id' => 41);
$_SERVER['REQUEST_METHOD'] = 'POST';
$config = array('poller_id' => 1, 'connection' => 'online', 'url_path' => '/');
$user_auth_roles = array('Graph & device roles' => array(7, 8, 101));
$user_auth_realms = array(7 => 'Configure Graph Management', 8 => 'View Device Management', 101 => 'Plugin -> Special', 10001 => 'First link', 10002 => 'Second link', 120 => 'Plugin -> %s Settings', 900 => 'Legacy -> Old & realm', 0 => 'Legacy -> Zero');
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec("CREATE TABLE user_auth_realm(user_id INTEGER, realm_id INTEGER, UNIQUE(user_id,realm_id));
    CREATE TABLE user_auth_group_realm(group_id INTEGER, realm_id INTEGER, UNIQUE(group_id,realm_id));
    CREATE TABLE external_links(id INTEGER, sortorder INTEGER, style TEXT, extendedstyle TEXT, title TEXT);
    INSERT INTO external_links VALUES(1,1,'CONSOLE','','one & link'),(2,2,'TAB','','two <link>');
    CREATE TABLE plugin_config(directory TEXT, name TEXT);
    INSERT INTO plugin_config VALUES('plug','Plugin');
    CREATE TABLE plugin_realms(id INTEGER, plugin TEXT, display TEXT);
    INSERT INTO plugin_realms VALUES(1,'plug','Already in roles'),(20,'plug','Plugin settings');");
foreach (array('user_auth_realm' => 'user_id', 'user_auth_group_realm' => 'group_id') as $table => $column) {
    foreach (array(42 => array(7, 101, 10001, 120, 900, 0), 43 => array(8, 10002)) as $principal => $realms) {
        foreach ($realms as $realm) {
            $db->prepare('INSERT INTO ' . $table . ' VALUES(?,?)')->execute(array($principal, $realm));
        }
    }
}
// Opposite principal kind must never supply the selected permission set.
$opposite = $group ? 'user_auth_realm' : 'user_auth_group_realm';
$db->exec('DELETE FROM ' . $opposite . ' WHERE ' . ($group ? 'user_id' : 'group_id') . '=42');
$db->exec('INSERT INTO ' . $opposite . ' VALUES(42,8)');
$db->exec('CREATE TABLE user_auth(id INTEGER PRIMARY KEY, reset_perms INTEGER DEFAULT 0); INSERT INTO user_auth(id) VALUES(41),(42),(43),(44); CREATE TABLE user_auth_group_members(group_id INTEGER,user_id INTEGER); INSERT INTO user_auth_group_members VALUES(42,42),(42,44),(43,43)');
$db->sqliteCreateFunction('RAND', static fn() => 0.5);
$db->sqliteCreateFunction('FLOOR', static fn($value) => floor($value));
$queries = array();
function realm_statement($sql, $params = array())
{
    $GLOBALS['queries'][] = array($sql, $params);
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function db_execute_prepared($sql, $params = array())
{
    return realm_statement($sql, $params)->rowCount();
}
function db_execute($sql)
{
    return $GLOBALS['db']->exec($sql);
}
function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}
function kill_session_var($name)
{
    unset($_SESSION[$name]);
}
function raise_message($message) {}
function is_error_message()
{
    return false;
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    return realm_statement($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell_prepared($sql, $params = array())
{
    return realm_statement($sql, $params)->fetchColumn();
}
function db_fetch_assoc($sql)
{
    return realm_statement($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function __($text, ...$arguments)
{
    return $arguments ? vsprintf($text, $arguments) : $text;
}
function __esc($text, ...$arguments)
{
    return html_escape(__($text, ...$arguments));
}
function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}
function get_request_var($name, $default = '')
{
    return $GLOBALS['request'][$name] ?? $default;
}
function get_nfilter_request_var($name, $default = '')
{
    return get_request_var($name, $default);
}
function get_filter_request_var($name)
{
    return (int) get_request_var($name);
}
function isset_request_var($name)
{
    return array_key_exists($name, $GLOBALS['request']);
}
function isempty_request_var($name)
{
    return empty(get_request_var($name));
}
function get_current_page()
{
    return $GLOBALS['group'] ? 'user_group_admin.php' : 'user_admin.php';
}
function clean_up_name($name)
{
    return $name;
}
function sanitize_uri($uri)
{
    return $uri;
}
function cacti_html_context_escape($value, $context)
{
    return $context === CACTI_ESC_JS_STRING ? substr(json_encode((string) $value), 1, -1) : html_escape($value);
}
function cacti_require_post_actions($actions) {}
function set_default_action() {}
function api_plugin_hook($name) {}
function api_plugin_hook_function($name, $value)
{
    return true;
}
final class CactiSecureHeaders
{
    public static function getNonceAttribute()
    {
        return "nonce='realm-native'";
    }
}
define('CACTI_ESC_ATTR', 'attribute');
define('CACTI_ESC_JS_STRING', 'javascript');
$snapshots = array();
register_shutdown_function(static function () use ($db, $group, &$snapshots) {
    $snapshots[] = render_realm_snapshot(42);
    $snapshots[] = render_realm_snapshot(0);
    $state = array('snapshots' => $snapshots, 'reset' => $db->query('SELECT * FROM user_auth ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'stored_realms' => $db->query('SELECT ' . ($group ? 'group_id' : 'user_id') . ' AS principal, realm_id FROM ' . ($group ? 'user_auth_group_realm' : 'user_auth_realm') . ' ORDER BY principal,realm_id')->fetchAll(PDO::FETCH_ASSOC));
    $GLOBALS['nativeChildCoverageMarkers'] = array('realm-snapshots-rendered', 'realm-state-readback');
    print json_encode($state, JSON_THROW_ON_ERROR);
});
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('REALM_RENDER_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/lib/html.php';
require $root . '/lib/html_form.php';
require $root . '/lib/auth.php';
require (getenv('PERMISSION_REALMS_CONTROLLER_ROOT') ?: $root) . '/' . get_current_page();
$function = $group ? 'user_group_realms_edit' : 'user_realms_edit';
function render_realm_snapshot($principal)
{
    global $request, $queries, $function;
    $request['id'] = $principal;
    $before = count($queries);
    ob_start();
    $function('Principal <' . $principal . '>');
    $html = ob_get_clean();
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html, LIBXML_NONET);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $checkboxes = array();
    foreach ($xpath->query('//input[starts-with(@id,"section")]') as $input) {
        $id = $input->getAttribute('id');
        $label = $xpath->query('//label[@for="' . $id . '"]')->item(0);
        $checkboxes[$id] = array('checked' => $input->hasAttribute('checked'), 'label' => $label->textContent, 'title' => $input->getAttribute('title'));
    }
    $permissionQueries = array_values(array_filter(array_slice($queries, $before), static fn($query) => str_contains($query[0], 'FROM user_auth_realm') || str_contains($query[0], 'FROM user_auth_group_realm')));
    return array('principal' => $principal, 'checkboxes' => $checkboxes, 'input_count' => $xpath->query('//input[starts-with(@id,"section")]')->length, 'queries' => $permissionQueries, 'html' => $html);
}
$snapshots = array(render_realm_snapshot(42), render_realm_snapshot(43));

$request['id'] = 42;
$request['save_component_realm_perms'] = '1';
$_POST = array('section8' => 'on', 'section10002' => 'on', 'unrelated' => 'on');
form_save();
