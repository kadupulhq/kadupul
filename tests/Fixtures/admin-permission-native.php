<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Exercise real admin handlers and cache-reset SQL after the separately tested
// authorization/CSRF/bootstrap boundary. Controller functions are never copied.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
if (isset($argv[3])) {
    require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/admin-permission-native.php', $argv[1], array('src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php', 'tests/Unit/Security/Auth/AdminPermissionPersistenceNativeCoverageTest.php', 'tests/Unit/Security/Auth/AdminPolicyAndMembershipNativeCoverageTest.php', 'user_admin.php', 'user_group_admin.php', 'lib/auth.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'));
}
$directory = $argv[2];
chdir($directory);
$group = $scenario['group'];
$target = 42;
$config = ['cacti_db_version' => '1.2.33'];
$operation = $scenario['operation'];
$request = ['action' => $operation === 'realm' ? 'save' : 'perm_remove', 'id' => $operation === 'realm' ? $target : ($scenario['item_id'] ?? 100), 'user_id' => $target, 'group_id' => $target, 'type' => $scenario['type'] ?? 'graph'];
$_POST = [];
if ($operation === 'add') {
    $request['action'] = 'save';
    $request['id'] = $target;
    $request['save_component_graph_perms'] = '1';
    $request['add_' . $scenario['type'] . '_x'] = '1';
    $request['perm_' . $scenario['field']] = $scenario['item'];
    $request += $scenario['extra_add_buttons'] ?? array();
    foreach (array('policy_graphs', 'policy_trees', 'policy_hosts', 'policy_graph_templates') as $policy) {
        $request[$policy] = 1;
    }
} elseif ($operation === 'policy') {
    $request['update_policy'] = '1';
    $request['id'] = $target;
    $request += $scenario['policies'];
} elseif ($operation === 'membership' && !isset($scenario['replace'])) {
    $request['action'] = 'fixture';
}
if ($operation === 'realm') {
    $request['save_component_realm_perms'] = '1';
    foreach ($scenario['realms'] as $realm) {
        $_POST['section' . $realm] = 'on';
    }
    $_POST['unrelated_field'] = 'on';
}
if ($operation === 'bulk' || ($operation === 'membership' && isset($scenario['replace']))) {
    $request['action'] = 'actions';
    $request['id'] = $target;
    $request['drp_action'] = $scenario['replace'] ? '1' : '2';
    $request[$operation === 'membership' ? ($group ? 'associate_member' : 'associate_groups') : 'associate_' . $scenario['kind']] = '1';
    foreach ($scenario['selected'] ?? array($operation === 'membership' ? 42 : 100) as $selected) {
        $_POST['chk_' . $selected] = 'on';
    }
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION = ['sess_user_id' => ($scenario['self'] ?? false) ? $target : 41, 'sess_user_perms_key' => 0, 'sess_user_realms' => [99], 'sess_user_config_array' => ['stale'], 'sess_config_array' => ['stale'], 'sess_auth_names' => ['stale']];
$initial_session = $_SESSION;
$messages = [];
final class AdminPermissionCountedStatement extends PDOStatement
{
    private bool $parentFetched = false;
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        $this->parentFetched = true;
        return $rows;
    }
    public function errorCode(): ?string
    {
        if ((($GLOBALS['scenario']['user_read_fault'] ?? false) ? str_starts_with($this->queryString, 'SELECT id, reset_perms FROM user_auth WHERE id IN (') : str_starts_with($this->queryString, 'SELECT id FROM user_auth_group WHERE id = ?')) && (($GLOBALS['scenario']['parent_read_fault'] ?? '') === 'early' || ($GLOBALS['scenario']['parent_read_fault'] ?? '') === 'late' && $this->parentFetched)) {
            return 'HY000';
        }
        return parent::errorCode();
    }
    public function execute(?array $params = null): bool
    {
        if (str_starts_with($this->queryString, 'DELETE FROM user_auth_group_perms')) {
            $GLOBALS['permission_delete_calls']++;
        }
        return parent::execute($params);
    }
}
$permission_delete_calls = 0;
$nativeDsn = getenv('KADUPUL_ADMIN_PERMISSION_TEST_DSN') ?: 'sqlite:' . $directory . '/state.sqlite';
final class AdminPermissionPdo extends PDO
{
    private bool $controlFault = false;
    public function rollBack(): bool
    {
        if (($GLOBALS['scenario']['parent_cleanup_failure'] ?? false) === 'active') {
            return true;
        }
        return ($GLOBALS['scenario']['parent_cleanup_failure'] ?? false) ? false : parent::rollBack();
    }
    public function errorCode(): ?string
    {
        return $this->controlFault ? 'HY000' : parent::errorCode();
    }
    public function exec(string $statement): int|false
    {
        $this->controlFault = false;
        if (($GLOBALS['scenario']['parent_cleanup_failure'] ?? false) && str_starts_with($statement, 'ROLLBACK TO SAVEPOINT ')) {
            $fault = $GLOBALS['scenario']['parent_cleanup_failure'];
            if ($fault === 'executed-hy000' || $fault === 'not-executed-hy000') {
                $result = $fault === 'executed-hy000' ? parent::exec($statement) : 0;
                $this->controlFault = true;
                return $result;
            }
            return false;
        }
        return parent::exec($statement);
    }
}
$db = new AdminPermissionPdo($nativeDsn, getenv('KADUPUL_ADMIN_PERMISSION_TEST_USER') ?: null, getenv('KADUPUL_ADMIN_PERMISSION_TEST_PASSWORD') ?: null);
$nativeSqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
if (!$nativeSqlite && $operation !== 'realm' && !($scenario['parent_contract'] ?? false)) {
    throw new RuntimeException('The supported-engine admin fixture currently registers only realm scenarios.');
}
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS, array(AdminPermissionCountedStatement::class));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database_hostname = 'native';
$database_port = '0';
$database_default = 'permission';
$database_sessions = array('native:0:permission' => $db);
// Native SQL's random reset marker stays a marker rather than a canned UPDATE.
if ($nativeSqlite) {
    $db->sqliteCreateFunction('RAND', static fn() => random_int(1, 4294967294) / 4294967295);
    $db->sqliteCreateFunction('FLOOR', static fn($value) => floor($value));
}
$epochColumn = $nativeSqlite ? 'INTEGER' : 'INT UNSIGNED';
$db->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, reset_perms ' . $epochColumn . ' DEFAULT 0)');
$db->exec('INSERT INTO user_auth (id) VALUES (41), (42), (43), (44)');
$db->exec('CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO user_auth_group VALUES (42), (43)');
foreach (array('policy_graphs', 'policy_trees', 'policy_hosts', 'policy_graph_templates') as $policy) {
    $db->exec('ALTER TABLE user_auth ADD COLUMN ' . $policy . ' INTEGER DEFAULT 1');
    $db->exec('ALTER TABLE user_auth_group ADD COLUMN ' . $policy . ' INTEGER DEFAULT 1');
}
$db->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER, UNIQUE(group_id, user_id))');
$db->exec('INSERT INTO user_auth_group_members VALUES (42, 42), (42, 44), (43, 43)');
$db->exec('CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER, UNIQUE(user_id, realm_id))');
$db->exec('INSERT INTO user_auth_realm VALUES (42, 7), (43, 9)');
$db->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER, UNIQUE(group_id, realm_id))');
$db->exec('INSERT INTO user_auth_group_realm VALUES (42, 7), (43, 9)');
$db->exec('CREATE TABLE user_auth_perms (user_id INTEGER, item_id INTEGER, type INTEGER, UNIQUE(user_id, item_id, type))');
$db->exec('CREATE TABLE user_auth_group_perms (group_id INTEGER, item_id INTEGER, type INTEGER, UNIQUE(group_id, item_id, type))');
foreach (range(1, 4) as $type) {
    $db->prepare('INSERT INTO user_auth_perms VALUES (42, 100, ?), (42, 101, ?), (43, 100, ?)')->execute([$type, $type, $type]);
    $db->prepare('INSERT INTO user_auth_group_perms VALUES (42, 100, ?), (42, 101, ?), (43, 100, ?)')->execute([$type, $type, $type]);
}
if ($scenario['replace'] ?? false) {
    if ($operation === 'membership') {
        $db->exec('DELETE FROM user_auth_group_members WHERE group_id = 42 AND user_id = 42');
    } else {
        $table = $group ? 'user_auth_group_perms' : 'user_auth_perms';
        $principal = $group ? 'group_id' : 'user_id';
        $db->prepare('DELETE FROM ' . $table . ' WHERE ' . $principal . ' = 42 AND item_id = 100 AND type = ?')->execute([$scenario['type_id']]);
    }
}
$write_outcomes = array();
if (isset($scenario['selected'])) {
    foreach ($scenario['selected'] as $selected) {
        if ($operation === 'membership') {
            $params = $group ? array(42, $selected) : array($selected, 42);
            $db->prepare(($scenario['replace'] ? 'DELETE FROM user_auth_group_members WHERE group_id = ? AND user_id = ?' : 'REPLACE INTO user_auth_group_members (group_id, user_id) VALUES (?, ?)'))->execute($params);
        } elseif ($scenario['replace'] ?? false) {
            $db->prepare('DELETE FROM ' . ($group ? 'user_auth_group_perms' : 'user_auth_perms') . ' WHERE ' . ($group ? 'group_id' : 'user_id') . ' = 42 AND item_id = ? AND type = ?')->execute(array($selected, $scenario['type_id']));
        }
    }
}
// Real SQL failures from a native trigger, translated to the legacy driver's
// false-on-error contract. Successful writes still use the same SQLite handle.
if (isset($scenario['failed_ids'])) {
    $table = $operation === 'membership' ? 'user_auth_group_members' : ($group ? 'user_auth_group_perms' : 'user_auth_perms');
    $column = $operation === 'membership' ? ($group ? 'user_id' : 'group_id') : 'item_id';
    $event = ($scenario['replace'] ?? false) ? 'INSERT' : 'DELETE';
    $row = $event === 'INSERT' ? 'NEW' : 'OLD';
    foreach ($scenario['failed_ids'] as $index => $failed) {
        $db->exec('CREATE TRIGGER failed_mutation_' . $index . ' BEFORE ' . $event . ' ON ' . $table . ' WHEN ' . $row . '.' . $column . ' = ' . (int) $failed . " BEGIN SELECT RAISE(ABORT, 'native permission write failure'); END");
    }
}
if (isset($scenario['epoch_failure'])) {
    $db->exec('CREATE TRIGGER reject_epoch BEFORE UPDATE OF reset_perms ON user_auth WHEN OLD.id = ' . (int) $scenario['epoch_failure'] . " BEGIN SELECT RAISE(ABORT, 'native epoch rejection'); END");
}
if (isset($scenario['epoch_mismatch'])) {
    $db->exec('CREATE TRIGGER restore_epoch AFTER UPDATE OF reset_perms ON user_auth WHEN OLD.id = ' . (int) $scenario['epoch_mismatch'] . ' BEGIN UPDATE user_auth SET reset_perms = OLD.reset_perms WHERE id = OLD.id; END');
}
if ($scenario['caller_transaction'] ?? false) {
    $db->exec('CREATE TABLE caller_work (id INTEGER PRIMARY KEY)');
    $db->beginTransaction();
    $db->exec('INSERT INTO caller_work VALUES (99)');
}
function input_validate_input_number($value)
{
    if (!ctype_digit((string) $value)) {
        throw new InvalidArgumentException('Invalid fixture numeric input.');
    }
}
function db_begin_transaction()
{
    return $GLOBALS['db']->beginTransaction();
}
function db_commit_transaction()
{
    return $GLOBALS['db']->commit();
}
function db_rollback_transaction()
{
    return $GLOBALS['db']->rollBack();
}
function db_execute_prepared($sql, $params = [], $log = true, $connection = false)
{
    if ($connection !== false && $connection !== $GLOBALS['db']) {
        throw new RuntimeException('Permission mutation changed PDO connection.');
    }
    if (($GLOBALS['scenario']['write_error'] ?? false) && (str_starts_with($sql, 'REPLACE INTO user_auth_perms') || str_starts_with($sql, 'UPDATE `user_auth` SET `policy_') || str_starts_with($sql, 'UPDATE `user_auth_group` SET `policy_'))) {
        return false;
    }
    $GLOBALS['permission_affected_rows'] = 0;
    try {
        $statement = $GLOBALS['db']->prepare($sql);
        $result = $statement->execute($params);
        $GLOBALS['permission_affected_rows'] = $statement->rowCount();
    } catch (PDOException $error) {
        if ((!isset($GLOBALS['scenario']['failed_ids']) || !str_contains($error->getMessage(), 'native permission write failure')) && !str_contains($error->getMessage(), 'native epoch rejection')) {
            throw $error;
        }
        $result = false;
    }
    if (str_starts_with($sql, 'REPLACE INTO user_auth') || str_starts_with($sql, 'DELETE FROM user_auth')) {
        $GLOBALS['write_outcomes'][] = array('parameters' => $params, 'success' => $result);
    }
    return $result;
}
function db_affected_rows($db_conn = false)
{
    if ($db_conn !== false && $db_conn !== $GLOBALS['db']) {
        throw new RuntimeException('Native admin changed affected-row PDO.');
    }
    return $GLOBALS['permission_affected_rows'] ?? false;
}
function db_execute($sql)
{
    try {
        return $GLOBALS['db']->exec($sql);
    } catch (PDOException $error) {
        if (!str_contains($error->getMessage(), 'native epoch rejection')) {
            throw $error;
        }
        return false;
    }
}
function db_fetch_assoc_prepared($sql, $params = [])
{
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function api_plugin_hook_function($hook, $value)
{
    // Isolate plugin routing so helper-only cases can load the real controller.
    return true;
}
function cacti_require_post_request()
{
    cacti_require_post_actions(array());
}
function array_rekey($rows, $key, $value)
{
    $result = [];
    foreach ($rows as $row) {
        $result[$row[$key]] = $row[$value];
    }
    return $result;
}
function __($text, ...$args)
{
    return $args ? vsprintf($text, $args) : $text;
}
function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}
function get_request_var($name)
{
    return $GLOBALS['request'][$name] ?? '';
}
function get_nfilter_request_var($name, $default = '')
{
    return $GLOBALS['request'][$name] ?? $default;
}
function get_filter_request_var($name)
{
    return (int) get_request_var($name);
}
function isset_request_var($name)
{
    return array_key_exists($name, $GLOBALS['request']);
}
function db_fetch_cell_prepared($sql, $params = [])
{
    // SQLite persistence cases cannot establish row-lock semantics. Leave
    // the real MySQL/MariaDB locking read intact for supported-engine cases.
    if ($GLOBALS['db']->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $sql = preg_replace('/ FOR UPDATE$/D', '', $sql);
    }
    $query = $GLOBALS['db']->prepare($sql);
    $query->execute($params);
    return $query->fetchColumn();
}
function cacti_version_compare($left, $right, $operator)
{
    return version_compare($left, $right, $operator);
}
function set_default_action() {}
function is_error_message()
{
    return $GLOBALS['scenario']['error'] ?? false;
}
function kill_session_var($name)
{
    unset($_SESSION[$name]);
}
function raise_message($key, ...$args)
{
    $GLOBALS['messages'][] = $key;
}
function get_client_addr()
{
    return '127.0.0.1';
}
function cacti_log($message, ...$args)
{
    $GLOBALS['parent_refusal_logs'][] = $message;
}
function cacti_require_post_actions($actions)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Native persistence fixture requires an intentional POST.');
    }
}
require $root . '/include/global_constants.php';
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('ADMIN_PERMISSION_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
require $root . '/lib/auth.php';
$GLOBALS['parent_refusal_logs'] = array();
if ($scenario['parent_missing'] ?? false) {
    $db->exec('DELETE FROM user_auth_group WHERE id=42');
}
if ($scenario['selected_group_missing'] ?? false) {
    $db->exec('DELETE FROM user_auth_group WHERE id=43');
}
if ($scenario['affected_user_missing'] ?? false) {
    $db->exec('DELETE FROM user_auth WHERE id = ' . ($group ? 43 : 42));
}
$GLOBALS['parent_before_state'] = array('permissions' => $db->query('SELECT * FROM ' . ($group ? 'user_auth_group_perms ORDER BY group_id' : 'user_auth_perms ORDER BY user_id') . ',item_id,type')->fetchAll(PDO::FETCH_ASSOC), 'memberships' => $db->query('SELECT * FROM user_auth_group_members ORDER BY group_id,user_id')->fetchAll(PDO::FETCH_ASSOC), 'reset' => $db->query('SELECT * FROM user_auth ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
ob_start();
register_shutdown_function(static function () use ($db, $group, $initial_session, $operation, $directory, $root, $scenario) {
    $output = ob_get_clean();
    $session = $_SESSION;
    // The old epoch is held by the existing session; query the actual reset
    // marker through the native validity helper after the controller writes.
    $perms_valid = is_user_perms_valid($session['sess_user_id']);
    $next_valid = null;
    $next_valid_accounts = array();
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && (in_array($operation, array('add', 'policy', 'bulk'), true) || ($operation === 'membership' && isset($scenario['replace'])))) {
        $program = <<<'PHP'
$config = array('cacti_db_version' => '1.2.33');
$account = (int) $argv[3];
$_SESSION = array('sess_user_id' => $account, 'sess_user_perms_key' => 0);
$db = new PDO('sqlite:' . $argv[1]);
function db_fetch_cell_prepared($sql, $params = array()) { $q = $GLOBALS['db']->prepare($sql); $q->execute($params); return $q->fetchColumn(); }
function cacti_version_compare($a, $b, $op) { return version_compare($a, $b, $op); }
require $argv[2];
print json_encode(is_user_perms_valid($account));
PHP;
        foreach (array(41, 42, 43, 44) as $account) {
            $process = proc_open([PHP_BINARY, '-r', $program, $directory . '/state.sqlite', $root . '/lib/auth.php', (string) $account], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $result = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0 || $errors !== '') {
                throw new RuntimeException($errors);
            }
            $next_valid_accounts[$account] = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
        }
        $next_valid = $next_valid_accounts[42];
    }
    $principal = $group ? 'group_id' : 'user_id';
    $realm_table = $group ? 'user_auth_group_realm' : 'user_auth_realm';
    $perm_table = $group ? 'user_auth_group_perms' : 'user_auth_perms';
    $state = ['controller_returned' => $GLOBALS['controller_returned'] ?? false, 'next_valid' => $next_valid, 'memberships' => $db->query('SELECT * FROM user_auth_group_members ORDER BY group_id, user_id')->fetchAll(PDO::FETCH_ASSOC), 'realms' => $db->query('SELECT * FROM ' . $realm_table . ' ORDER BY ' . $principal . ', realm_id')->fetchAll(PDO::FETCH_ASSOC), 'permissions' => $db->query('SELECT * FROM ' . $perm_table . ' ORDER BY ' . $principal . ', item_id, type')->fetchAll(PDO::FETCH_ASSOC), 'reset' => $db->query('SELECT * FROM user_auth ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'session' => $session, 'perms_valid' => $perms_valid, 'initial_session' => $initial_session, 'messages' => $GLOBALS['messages'], 'output' => $output, 'policies' => $db->query('SELECT id, policy_graphs, policy_trees, policy_hosts, policy_graph_templates FROM ' . ($group ? 'user_auth_group' : 'user_auth') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'membership' => $GLOBALS['membership'] ?? null];
    $state['transaction_open'] = $db->inTransaction();
    $state['caller_work'] = ($scenario['caller_transaction'] ?? false) ? (int) $db->query('SELECT COUNT(*) FROM caller_work')->fetchColumn() : null;
    if ($scenario['caller_transaction'] ?? false) {
        $confirmed = $db->rollBack();
        $state['caller_rollback_confirmed'] = $confirmed;
        $state[$confirmed ? 'after_caller_rollback' : 'caller_cleanup_state'] = array('permissions' => $db->query('SELECT * FROM ' . $perm_table . ' ORDER BY ' . $principal . ', item_id, type')->fetchAll(PDO::FETCH_ASSOC), 'reset' => $db->query('SELECT id, reset_perms FROM user_auth ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'caller_work' => (int) $db->query('SELECT COUNT(*) FROM caller_work')->fetchColumn());
    }
    $state['next_valid_accounts'] = $next_valid_accounts;
    $state['write_outcomes'] = $GLOBALS['write_outcomes'];
    $state['permission_delete_calls'] = $GLOBALS['permission_delete_calls'];
    $state['parent_before_state'] = $GLOBALS['parent_before_state'];
    $state['parent_refusal_logs'] = $GLOBALS['parent_refusal_logs'];
    $GLOBALS['nativeChildCoverageMarkers'] = array('admin-state-readback', 'permission-epoch-checked', 'mutation-sql-outcomes-readback');
    if ($next_valid !== null) {
        $GLOBALS['nativeChildCoverageMarkers'][] = 'next-request-epoch-checked';
    }
    print json_encode($state, JSON_THROW_ON_ERROR);
});
$controller_returned = false;
require $root . ($group ? '/user_group_admin.php' : '/user_admin.php');
$controller_returned = true;

if ($operation === 'membership' && !isset($scenario['replace'])) {
    $membership = array(
        'target_member' => user_group_is_member(42, 42),
        'other_member' => user_group_is_member(44, 42),
        'foreign_member' => user_group_is_member(43, 42),
        'foreign_group' => user_group_is_member(42, 43),
        'target_realm' => is_user_group_realm_allowed(7, 42),
        'foreign_realm' => is_user_group_realm_allowed(9, 42),
        'foreign_realm_owner' => is_user_group_realm_allowed(9, 43),
        'missing_group' => is_user_group_realm_allowed(7, 99),
    );
}
