<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
mkdir($directory . '/include', 0700, true);
file_put_contents($directory . '/include/auth.php', '<?php');
chdir($directory);
$config = array('poller_id' => 1, 'connection' => 'online', 'url_path' => '/');
$item_rows = array(10 => 'Ten', 25 => 'Twenty & five');
$request = array_merge(array('id' => 7, 'rows' => 25, 'associated' => 'true', 'filter' => 'A & B', 'graph_template_id' => 3, 'host_template_id' => 4), $scenario);
$request['action'] = 'fixture';
$db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec("CREATE TABLE graph_templates(id INTEGER, name TEXT);
    CREATE TABLE graph_local(graph_template_id INTEGER);
    CREATE TABLE host_template(id INTEGER, name TEXT);
    INSERT INTO graph_templates VALUES(3, 'Z & graph'), (8, 'Unused'), (9, 'A graph');
    INSERT INTO graph_local VALUES(3),(3),(9);
    INSERT INTO host_template VALUES(4,'Z & host'),(5,'A host');");
if (!empty($scenario['empty'])) {
    $db->exec('DELETE FROM graph_templates; DELETE FROM graph_local; DELETE FROM host_template');
    $item_rows = false;
}
$queries = array();
if (isset($scenario['association'])) {
    $request[$scenario['association']] = '1';
    $request['drp_action'] = $scenario['add'] ? '1' : '2';
    $_POST = array('chk_41' => 'on', 'chk_43' => '', 'chk_invalid' => 'on', 'chk_42_extra' => 'on');
    $db->exec('CREATE TABLE user_auth_perms(user_id INTEGER, item_id INTEGER, type INTEGER, UNIQUE(user_id,item_id,type));
        CREATE TABLE user_auth_group_perms(group_id INTEGER, item_id INTEGER, type INTEGER, UNIQUE(group_id,item_id,type));
        CREATE TABLE user_auth_group_members(group_id INTEGER, user_id INTEGER, UNIQUE(group_id,user_id));');
    $group = $scenario['page'] === 'user_group_admin.php';
    $subjectColumn = $group ? 'group_id' : 'user_id';
    $table = $scenario['type'] === 0 ? 'user_auth_group_members' : ($group ? 'user_auth_group_perms' : 'user_auth_perms');
    $itemColumn = $scenario['type'] === 0 ? ($group ? 'user_id' : 'group_id') : 'item_id';
    $columns = $subjectColumn . ',' . $itemColumn . ($scenario['type'] === 0 ? '' : ',type');
    foreach (array(array(7, 41), array(7, 42), array(77, 41)) as $row) {
        if ($scenario['type'] !== 0) {
            $row[] = $scenario['type'];
        }
        $statement = $db->prepare('INSERT INTO ' . $table . ' (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
        $statement->execute($row);
    }
}
function db_execute_prepared($sql, $parameters)
{
    $GLOBALS['queries'][] = array($sql, $parameters);
    return $GLOBALS['db']->prepare($sql)->execute($parameters);
}
function input_validate_input_number($number)
{
    if (!ctype_digit((string) $number)) {
        throw new InvalidArgumentException('Expected a numeric selection');
    }
}
function db_fetch_assoc($sql)
{
    $GLOBALS['queries'][] = $sql;
    return $GLOBALS['db']->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}
function __($text, ...$arguments)
{
    return $arguments ? vsprintf($text, $arguments) : $text;
}
function __esc($text, ...$arguments)
{
    return html_escape(__($text, ...$arguments));
}
function __x($context, $text)
{
    return $context . ':' . $text;
}
function get_request_var($name)
{
    return $GLOBALS['request'][$name] ?? '';
}
function get_nfilter_request_var($name)
{
    return get_request_var($name);
}
function isset_request_var($name)
{
    return array_key_exists($name, $GLOBALS['request']);
}
function isempty_request_var($name)
{
    return get_request_var($name) === '';
}
function get_current_page()
{
    return $GLOBALS['request']['page'];
}
function clean_up_name($name)
{
    return $name;
}
function is_realm_allowed($realm)
{
    return false;
}
function api_plugin_hook($name) {}
function cacti_require_post_actions($actions) {}
function set_default_action() {}
// Stop unrelated dispatch using the controller's native plugin boundary.
function api_plugin_hook_function($name, $value)
{
    return true;
}
final class CactiSecureHeaders
{
    public static function getNonceAttribute()
    {
        return "nonce='permission-fixture'";
    }
}
require $root . '/lib/html.php';
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('PERMISSION_FILTER_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
require (getenv('PERMISSION_FILTER_CONTROLLER_ROOT') ?: $root) . '/' . $scenario['page'];
if (isset($scenario['association'])) {
    register_shutdown_function(static function () use ($db, $table, $subjectColumn, $itemColumn) {
        print json_encode(array('rows' => $db->query('SELECT ' . $subjectColumn . ' AS subject, ' . $itemColumn . ' AS item FROM ' . $table . ' ORDER BY subject, item')->fetchAll(PDO::FETCH_ASSOC), 'queries' => $GLOBALS['queries']), JSON_THROW_ON_ERROR);
    });
    form_actions();
    throw new RuntimeException('Association did not complete its controller redirect');
}
ob_start();
($scenario['function'] . '_filter')('Example');
$html = ob_get_clean();
// Browser behavior tests execute the script nodes parsed from the actual markup.
$document = new DOMDocument();
$previous_errors = libxml_use_internal_errors(true);
try {
    if (!$document->loadHTML($html, LIBXML_NONET)) {
        throw new RuntimeException('Could not parse the native permission filter markup.');
    }
} finally {
    libxml_clear_errors();
    libxml_use_internal_errors($previous_errors);
}
$scripts = array();
foreach ($document->getElementsByTagName('script') as $script) {
    $scripts[] = $script->textContent;
}
print json_encode(array('html' => $html, 'queries' => $queries, 'scripts' => $scripts), JSON_THROW_ON_ERROR);
