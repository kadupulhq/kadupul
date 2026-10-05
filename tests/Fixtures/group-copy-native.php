<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$scenario = $argv[1];
$directory = $argv[2];
require $root . '/include/vendor/autoload.php';
if (str_starts_with($scenario, 'wiring-')) {
    require $root . '/tests/vendor/autoload.php';
}
require $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
$db = null;
$messages = array();
$status = null;
$cause = null;
$dispatch = false;
// Observe actual persisted outcomes before the collector shutdown writer runs.
register_shutdown_function(static function () use (&$db, &$messages, &$status, &$cause, $directory, $scenario): void {
    if (!$db instanceof PDO) {
        return;
    }
    $read = static fn() => array('copies' => $db->query('SELECT id,name,description FROM user_auth_group WHERE id NOT IN (5,7) ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'perms' => $db->query('SELECT group_id,item_id,type FROM user_auth_group_perms ORDER BY group_id,item_id,type')->fetchAll(PDO::FETCH_ASSOC), 'realms' => $db->query('SELECT group_id,realm_id FROM user_auth_group_realm ORDER BY group_id,realm_id')->fetchAll(PDO::FETCH_ASSOC), 'caller' => (int) $db->query('SELECT COUNT(*) FROM caller_work')->fetchColumn());
    $state = $read();
    $state['status'] = $status;
    $state['cause'] = $cause;
    $state['messages'] = $messages;
    $state['transaction'] = $db->inTransaction();
    $state['persisted_before_fault'] = $db->persistedFault;
    $state['parent_insert_refused'] = $db->parentInsertRefused;
    if ($db->inTransaction()) {
        $db->fault = '';
        $db->rollBack();
        $state['after_rollback'] = $read();
    }
    $state['driver'] = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $state['version'] = $db->query($state['driver'] === 'mysql' ? 'SELECT VERSION()' : 'SELECT sqlite_version()')->fetchColumn();
    $GLOBALS['nativeChildCoverageMarkers'] = array('persisted-group-copy-observed');
    print json_encode($state, JSON_THROW_ON_ERROR);
    $db->fault = '';
    foreach (array('caller_work', 'user_auth_group_realm', 'user_auth_group_perms', 'user_auth_group') as $table) {
        $db->exec('DROP TABLE IF EXISTS ' . $table);
    }
    if (is_file($directory . '/include/auth.php')) {
        unlink($directory . '/include/auth.php');
        rmdir($directory . '/include');
    }
});
if (($argv[3] ?? '') === 'coverage') {
    $sources = array('composer.lock', 'tests/composer.lock', 'tests/Symfony/GroupCopyTransactionTest.php', 'cacti.sql', 'lib/auth.php', 'lib/functions.php', 'tests/Helpers/PhpSource.php', 'lib/database.php', 'user_group_admin.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
    if (str_starts_with($scenario, 'wiring-')) {
        $sources[] = 'tests/Unit/Security/Auth/GroupCopyTest.php';
    }
    $GLOBALS['nativeChildCoverageSnapshot'] = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/group-copy-native.php', $scenario, $sources);
    define('GROUP_COPY_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
require $root . '/include/global_constants.php';
require $root . '/lib/database.php';
require $root . '/lib/auth.php';
require $root . '/tests/Helpers/PhpSource.php';
eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'clean_up_lines'));
class GroupCopyStatement extends PDOStatement
{
    private bool $fetched = false;
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        $this->fetched = true;
        return $rows;
    }
    public function errorCode(): ?string
    {
        $owner = $GLOBALS['db'];
        if (($owner->fault === 'parent-sqlstate' && str_starts_with($this->queryString, 'INSERT INTO user_auth_group (')) || ($owner->fault === 'child-sqlstate' && str_starts_with($this->queryString, 'INSERT INTO user_auth_group_perms'))) {
            $owner->persistedFault = (int) $owner->query('SELECT COUNT(*) FROM user_auth_group WHERE id NOT IN (5,7)')->fetchColumn();
            return 'HY000';
        }
        if (($owner->fault === 'source-read' || $owner->fault === 'late-source' && $this->fetched) && str_starts_with($this->queryString, 'SELECT item_id, type FROM user_auth_group_perms')) {
            return 'HY000';
        }
        return parent::errorCode();
    }
}
class GroupCopyPdo extends PDO
{
    public string $fault = '';
    public int $childPrepares = 0;
    public ?int $persistedFault = null;
    public bool $parentInsertRefused = false;
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $query = str_replace(' FOR UPDATE', '', $query);
        }
        if ($this->fault === 'parent-insert' && str_starts_with($query, 'INSERT INTO user_auth_group (')) {
            $this->parentInsertRefused = true;
            return false;
        }
        if (str_starts_with($query, 'INSERT INTO user_auth_group_perms')) {
            $this->childPrepares++;
            if (in_array($this->fault, array('prepare', 'cleanup-failed'), true) && $this->childPrepares === 2) {
                return false;
            }
        }
        return parent::prepare($query, $options);
    }
    public function rollBack(): bool
    {
        return $this->fault === 'cleanup-failed' ? false : parent::rollBack();
    }
    public function exec(string $statement): int|false
    {
        return $this->fault === 'cleanup-failed' && str_starts_with($statement, 'ROLLBACK TO SAVEPOINT ') ? false : parent::exec($statement);
    }
    public function commit(): bool
    {
        return $this->fault === 'commit' ? false : parent::commit();
    }
}
$db = new GroupCopyPdo(getenv('KADUPUL_MEMBERSHIP_TEST_DSN') ?: 'sqlite::memory:', getenv('KADUPUL_MEMBERSHIP_TEST_USER') ?: null, getenv('KADUPUL_MEMBERSHIP_TEST_PASSWORD') ?: null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT, PDO::ATTR_STATEMENT_CLASS => array(GroupCopyStatement::class)));
$database_hostname = 'fixture';
$database_port = 0;
$database_default = 'auth';
$database_sessions = array('fixture:0:auth' => $db);
$config = array();
$_SESSION = array();
function cacti_count($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log(...$args) {} function cacti_debug_backtrace(...$args) {} function cacti_require_post_actions($actions) {} function set_default_action() {}
function isset_request_var($name)
{
    return $GLOBALS['dispatch'] && isset($_POST[$name]);
}
function get_request_var($name)
{
    return 'native-test';
}
function get_nfilter_request_var($name)
{
    return $_POST[$name] ?? '';
}
function sanitize_unserialize_selected_items($value)
{
    return unserialize($value, array('allowed_classes' => false));
}
function input_validate_input_number($value)
{
    if (!ctype_digit((string) $value)) {
        throw new RuntimeException('Invalid fixture identifier');
    }
}
function raise_message($code, ...$args)
{
    $GLOBALS['messages'][] = $code;
}
function api_plugin_hook_function($name, $value = null)
{
    return true;
}
function __($value, ...$args)
{
    return vsprintf($value, $args);
}
$mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
if ($mysql) {
    $schema = file_get_contents($root . '/cacti.sql');
    foreach (array('user_auth_group', 'user_auth_group_perms', 'user_auth_group_realm') as $table) {
        if (!preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \(.*?;/s', $schema, $match) || $db->exec($match[0]) === false) {
            throw new RuntimeException('Group-copy schema not created');
        }
    }
} else {
    $db->exec("CREATE TABLE user_auth_group(id INTEGER PRIMARY KEY AUTOINCREMENT,name VARCHAR(20) NOT NULL,description VARCHAR(255) NOT NULL DEFAULT '',graph_settings VARCHAR(2),login_opts INTEGER NOT NULL DEFAULT 1,show_tree VARCHAR(2) DEFAULT 'on',show_list VARCHAR(2) DEFAULT 'on',show_preview VARCHAR(2) NOT NULL DEFAULT 'on',policy_graphs INTEGER NOT NULL DEFAULT 1,policy_trees INTEGER NOT NULL DEFAULT 1,policy_hosts INTEGER NOT NULL DEFAULT 1,policy_graph_templates INTEGER NOT NULL DEFAULT 1,enabled CHAR(2) NOT NULL DEFAULT 'on')");
    $db->exec('CREATE TABLE user_auth_group_perms(group_id INTEGER,item_id INTEGER,type INTEGER,PRIMARY KEY(group_id,item_id,type))');
    $db->exec('CREATE TABLE user_auth_group_realm(group_id INTEGER,realm_id INTEGER,PRIMARY KEY(group_id,realm_id))');
}
$db->exec('CREATE TABLE caller_work(id INTEGER PRIMARY KEY)' . ($mysql ? ' ENGINE=InnoDB' : ''));
$db->exec("INSERT INTO user_auth_group(id,name,description) VALUES(5,'source','policy'),(7,'unrelated','other')");
$db->exec('INSERT INTO user_auth_group_perms VALUES(5,9,1),(5,10,1),(7,11,2)');
$db->exec('INSERT INTO user_auth_group_realm VALUES(5,8),(7,9)');
if (str_starts_with($scenario, 'wiring-')) {
    $db->exec('DELETE FROM user_auth_group_perms WHERE group_id=5');
    $db->exec('DELETE FROM user_auth_group_realm WHERE group_id=5');
    $db->exec('INSERT INTO user_auth_group_perms VALUES(5,12,2),(5,30,3)');
    $db->exec('INSERT INTO user_auth_group_realm VALUES(5,7),(5,8)');
}
if (str_contains($scenario, 'empty')) {
    $db->exec('DELETE FROM user_auth_group_perms WHERE group_id=5');
    $db->exec('DELETE FROM user_auth_group_realm WHERE group_id=5');
}
$denied = str_contains($scenario, 'denied') || str_contains($scenario, 'bulk');
if ($denied) {
    $sql = $mysql ? "CREATE TRIGGER deny_copy BEFORE INSERT ON user_auth_group_perms FOR EACH ROW BEGIN IF NEW.group_id NOT IN (5,7) AND NEW.item_id=10 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture later child denied'; END IF; END" : "CREATE TRIGGER deny_copy BEFORE INSERT ON user_auth_group_perms WHEN NEW.group_id NOT IN (5,7) AND NEW.item_id=10 BEGIN SELECT RAISE(FAIL,'fixture later child denied'); END";
    if ($db->exec($sql) === false) {
        throw new RuntimeException('Group-copy denial not installed');
    }
}
if (str_contains($scenario, 'mismatch')) {
    $sql = $mysql ? 'CREATE TRIGGER mismatch_copy BEFORE INSERT ON user_auth_group_realm FOR EACH ROW BEGIN IF NEW.group_id NOT IN (5,7) THEN SET NEW.realm_id=NEW.realm_id+1; END IF; END' : 'CREATE TRIGGER mismatch_copy AFTER INSERT ON user_auth_group_realm WHEN NEW.group_id NOT IN (5,7) BEGIN UPDATE user_auth_group_realm SET realm_id=realm_id+1 WHERE group_id=NEW.group_id; END';
    if ($db->exec($sql) === false) {
        throw new RuntimeException('Group-copy mismatch not installed');
    }
}
foreach (array('cleanup-failed', 'prepare', 'commit', 'parent-sqlstate', 'child-sqlstate', 'source-read', 'late-source', 'parent-insert') as $fault) {
    if (str_contains($scenario, $fault)) {
        $db->fault = $fault;
    }
}
if (str_contains($scenario, 'caller')) {
    $db->beginTransaction();
    $db->exec('INSERT INTO caller_work VALUES(77)');
}
mkdir($directory . '/include', 0700);
file_put_contents($directory . '/include/auth.php', '<?php');
chdir($directory);
require $root . '/user_group_admin.php';
if (str_contains($scenario, 'bulk')) {
    $_POST = array('selected_items' => serialize(str_contains($scenario, 'success-first') ? array(7,5) : array(5,7)), 'drp_action' => '2', 'group_prefix' => 'Copied');
    $dispatch = true;
    form_actions();
}
try {
    $status = user_group_copy(str_contains($scenario, 'absent') ? 999 : 5, 'Copied');
} catch (Throwable $error) {
    if (!str_contains($scenario, 'cleanup-failed')) {
        throw $error;
    }
    $status = 'thrown';
    $cause = $error->getMessage();
}
