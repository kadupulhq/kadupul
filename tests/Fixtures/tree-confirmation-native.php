<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Execute the complete controller with persisted ownership. Page chrome and
// the DB adapter are isolated. The owned loopback transport can verify actual
// response headers; application bootstrap and MySQL locking remain outside it.
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
if (isset($argv[3])) {
    require $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    require $root . '/tests/Helpers/TreeConfirmationCoverageRegistration.php';
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/tree-confirmation-native.php', $argv[1], TreeConfirmationCoverageRegistration::SOURCES);
    define('TREE_CONFIRMATION_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/lib/auth.php';
require $root . '/lib/html_utility.php';
require $root . '/include/global_constants.php';
foreach (array('sanitize_unserialize_selected_items', 'cacti_sizeof', 'escape_page_action') as $name) {
    $source = file_get_contents($root . '/lib/functions.php');
    if ($source === false) throw new RuntimeException('Cannot read actual tree function dependency.');
    eval(test_php_function_source($source, $name)); // nosemgrep: php.lang.security.eval-use.eval-use
}
foreach (array('lib/database.php' => array('array_to_sql_or'), 'lib/html.php' => array('html_escape_charset', 'html_escape')) as $file => $names) {
    $source = file_get_contents($root . '/' . $file);
    if ($source === false) throw new RuntimeException('Cannot read actual tree source dependency.');
    foreach ($names as $name) {
        eval(test_php_function_source($source, $name)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
}
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('NOW', static fn(): string => '2026-10-05 12:00:00');
$db->exec("CREATE TABLE graph_tree(id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, enabled TEXT, locked INTEGER, last_modified TEXT, modified_by INTEGER);
    CREATE TABLE graph_tree_items(id INTEGER PRIMARY KEY, graph_tree_id INTEGER);
    CREATE TABLE user_auth_realm(user_id INTEGER, realm_id INTEGER);
    INSERT INTO graph_tree VALUES(7,42,'Owned & tree','off',1,NULL,NULL),(8,43,'Foreign secret tree','off',1,NULL,NULL);
    INSERT INTO graph_tree_items VALUES(70,7),(80,8);");
if ($scenario['ownerless'] ?? false) $db->exec("INSERT INTO graph_tree VALUES(9,0,'Unowned secret tree','off',1,NULL,NULL)");
if ($scenario['admin'] ?? false) $db->exec('INSERT INTO user_auth_realm VALUES(42,1)');
$lookups = $writes = $messages = $settings = array();
session_id('tree-confirmation-native');
$_SESSION = array('sess_user_id' => 42);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_REQUEST = $_POST = array('action' => 'actions', 'drp_action' => (string) $scenario['action']);
$_GET = array();
if ($scenario['submit'] ?? false) {
    $_REQUEST['selected_items'] = $_POST['selected_items'] = serialize($scenario['ids']);
} else {
    foreach ($scenario['ids'] as $id) $_REQUEST['chk_' . $id] = $_POST['chk_' . $id] = 'on';
}
function csrf_startup(): void
{
    csrf_conf('rewrite', false);
    csrf_conf('defer', true);
    csrf_conf('auto-session', false);
    csrf_conf('secret', 'isolated-tree-confirmation-secret');
}
require $root . '/include/vendor/csrf/csrf-magic.php';
$_POST['__csrf_magic'] = csrf_get_tokens();
function db_fetch_cell_prepared($sql, $params = array())
{
    if (str_contains($sql, 'SELECT name')) $GLOBALS['lookups'][] = (int) $params[0];
    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}
function db_qstr($value): string
{
    return $GLOBALS['db']->quote((string) $value);
}
function db_execute($sql): bool
{
    $GLOBALS['writes'][] = $sql;
    return $GLOBALS['db']->exec($sql) !== false;
}
function set_config_option($name, $value): void
{
    $GLOBALS['settings'][$name] = $value;
}
function get_current_page(): string
{
    return 'tree.php';
}
function read_config_option($name): string
{
    return '';
}
function input_validate_input_number($value): void
{
    if (!ctype_digit((string) $value)) throw new RuntimeException('Invalid fixture id.');
}
function __($value): string
{
    return $value;
}
function __x($context, $value): string
{
    return $value;
}
function __esc($value): string
{
    return htmlspecialchars($value, ENT_QUOTES);
}
function __n($one, $many, $count): string
{
    return $count === 1 ? $one : $many;
}
function top_header(): void
{
    echo '<main>';
}
function bottom_footer(): void
{
    echo '</main>';
}
function form_start($action): void
{
    echo '<form method="post" action="' . html_escape($action) . '">';
}
function form_end(): void
{
    echo '</form>';
}
function html_start_box(...$args): void
{
    echo '<table>';
}
function html_end_box(): void
{
    echo '</table>';
}
function raise_message($code, ...$args): void
{
    $GLOBALS['messages'][] = $code;
}

ob_start();
register_shutdown_function(static function (): void {
    $output = ob_get_clean();
    $state = array('html' => $output, 'lookups' => $GLOBALS['lookups'], 'writes' => $GLOBALS['writes'],
        'messages' => $GLOBALS['messages'], 'settings' => array_keys($GLOBALS['settings']),
        'trees' => $GLOBALS['db']->query('SELECT * FROM graph_tree ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'items' => $GLOBALS['db']->query('SELECT id FROM graph_tree_items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    $encoded = json_encode($state, JSON_THROW_ON_ERROR);
    $GLOBALS['nativeChildCoverageMarkers'] = array('tree-controller-state-readback', 'tree-controller-output-readback');
    if ($GLOBALS['scenario']['http'] ?? false) {
        $state['response_headers'] = headers_list();
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        $GLOBALS['nativeChildCoverageMarkers'][] = 'tree-controller-response-headers-readback';
    }
    echo $encoded;
});
if (!mkdir($directory . '/include', 0700) || file_put_contents($directory . '/include/auth.php', '<?php') === false
    || !symlink($root . '/lib', $directory . '/lib') || !chdir($directory)) {
    throw new RuntimeException('Cannot prepare isolated tree controller bootstrap.');
}
require $root . '/tree.php';
