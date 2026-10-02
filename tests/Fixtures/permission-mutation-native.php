<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$directory = $argv[2];
$coverageSources = array('src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php', 'src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php', 'lib/auth.php', 'lib/database.php', 'include/global_constants.php', 'tests/Unit/Security/Auth/PermissionMutationNativeTest.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
if (isset($argv[3])) {
    require $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/permission-mutation-native.php', $argv[1], $coverageSources);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('PERMISSION_MUTATION_TEST_COVERAGE', true);
    require __DIR__ . '/rrd-process-coverage.php';
}
$driver = $scenario['engine'];
final class PermissionReceiptStatement extends PDOStatement
{
    protected function __construct() {}

    public function closeCursor(): bool
    {
        $result = parent::closeCursor();
        // Exercise the actual driver's receipt read after its real DELETE completes.
        if (array_key_exists('affected_outcome', $GLOBALS['scenario']) && str_starts_with($this->queryString, 'DELETE FROM user_auth')) {
            $GLOBALS['affected_rows'][spl_object_hash($GLOBALS['db'])] = $GLOBALS['scenario']['affected_outcome'];
        }
        return $result;
    }
}
final class PermissionCountedPdo extends PDO
{
    public int $calls = 0;
    public array $queries = array();
    public function prepare(string $query, array $options = array()): PDOStatement|false
    {
        $this->calls++;
        $this->queries[] = $query;
        return parent::prepare($query, $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->calls++;
        $this->queries[] = $query;
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
    public function exec(string $statement): int|false
    {
        $this->calls++;
        $this->queries[] = $statement;
        return parent::exec($statement);
    }
}
$db = $driver === 'sqlite'
    ? new PermissionCountedPdo('sqlite:' . $directory . '/state.sqlite')
    : new PermissionCountedPdo((string) getenv('PERMISSION_MUTATION_TEST_DSN'), (string) getenv('PERMISSION_MUTATION_TEST_USER'), (string) getenv('PERMISSION_MUTATION_TEST_PASSWORD'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (array_key_exists('affected_outcome', $scenario)) {
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, array(PermissionReceiptStatement::class, array()));
}
if ($driver !== 'sqlite') {
    $schema = $db->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($schema) || !preg_match('/^kadupul715_epoch_[a-z0-9_]+$/D', $schema)) {
        throw new RuntimeException('Native permission test requires its isolated schema.');
    }
}
$database_hostname = 'native';
$database_port = '0';
$database_default = 'permission';
$database_sessions = array('native:0:permission' => $db);
$config = array('cacti_db_version' => '1.2.33');
$_SESSION = array('sess_user_id' => 41);
if ($scenario['self'] ?? false) {
    $_SESSION = array('sess_user_id' => 42, 'sess_user_realms' => array('preserved'));
}
$initialSession = $_SESSION;
$messages = array();
function cacti_count($value)
{
    return count($value);
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function clean_up_lines($value)
{
    return $value;
}
function cacti_log(...$arguments) {}
function cacti_debug_backtrace(...$arguments) {}
function kill_session_var($name)
{
    unset($_SESSION[$name]);
}
function raise_message($key, ...$arguments)
{
    $GLOBALS['messages'][] = $key;
}
function input_validate_input_number($value)
{
    if (!ctype_digit((string) $value)) {
        throw new RuntimeException('Invalid numeric fixture input.');
    }
}
function isset_request_var($name)
{
    return isset($GLOBALS['request'][$name]);
}
function get_nfilter_request_var($name)
{
    return $GLOBALS['request'][$name];
}
function get_filter_request_var($name)
{
    return $GLOBALS['request'][$name];
}
require $root . '/include/global_constants.php';
require $root . '/lib/database.php';
require $root . '/src/IdentityAccess/Infrastructure/Legacy/PermissionAssociations.php';

$engine = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB';
foreach (array('user_auth_group_members', 'user_auth_group_perms', 'user_auth_perms', 'user_auth_group', 'user_auth', 'caller_work') as $table) {
    $db->exec('DROP TABLE IF EXISTS ' . $table);
}
$db->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, reset_perms ' . ($driver === 'sqlite' ? 'INTEGER' : 'INT UNSIGNED') . ' NOT NULL DEFAULT 0, policy_graphs INTEGER NOT NULL DEFAULT 2)' . $engine);
$db->exec('CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, policy_graphs INTEGER NOT NULL DEFAULT 2)' . $engine);
$db->exec('CREATE TABLE user_auth_perms (user_id INTEGER, item_id INTEGER, type INTEGER, PRIMARY KEY(user_id,item_id,type))' . $engine);
$db->exec('CREATE TABLE user_auth_group_perms (group_id INTEGER, item_id INTEGER, type INTEGER, PRIMARY KEY(group_id,item_id,type))' . $engine);
$db->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER, PRIMARY KEY(group_id,user_id))' . $engine);
$db->exec('CREATE TABLE caller_work (id INTEGER PRIMARY KEY)' . $engine);
$db->exec('INSERT INTO user_auth VALUES (41,0,2),(42,' . (int) ($scenario['epoch'] ?? 7) . ',2),(43,7,2),(44,7,2)');
$db->exec('INSERT INTO user_auth_group VALUES (42,2),(43,2)');
$db->exec('INSERT INTO user_auth_group_members VALUES (42,42),(42,44),(43,43)');
$db->exec('INSERT INTO user_auth_perms VALUES (42,100,1),(42,101,1),(43,100,1)');
$db->exec('INSERT INTO user_auth_group_perms VALUES (42,100,1),(42,101,1),(43,100,1)');
if (($scenario['members'] ?? 2) > 2) {
    $account = $db->prepare('INSERT INTO user_auth VALUES (?,7,2)');
    $membership = $db->prepare('INSERT INTO user_auth_group_members VALUES (42,?)');
    for ($i = 45; $i < 43 + $scenario['members']; $i++) {
        $account->execute(array($i));
        $membership->execute(array($i));
    }
}
$failure = $scenario['failure'] ?? '';
if ($failure === 'epoch') {
    $id = (int) ($scenario['failed_user'] ?? 42);
    $db->exec($driver === 'sqlite'
        ? "CREATE TRIGGER reject_epoch BEFORE UPDATE OF reset_perms ON user_auth WHEN OLD.id = $id BEGIN SELECT RAISE(ABORT,'native epoch rejection'); END"
        : "CREATE TRIGGER reject_epoch BEFORE UPDATE ON user_auth FOR EACH ROW BEGIN IF OLD.id = $id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='native epoch rejection'; END IF; END");
} elseif ($failure === 'mismatch') {
    $db->exec($driver === 'sqlite'
        ? 'CREATE TRIGGER reject_epoch AFTER UPDATE OF reset_perms ON user_auth BEGIN UPDATE user_auth SET reset_perms=OLD.reset_perms WHERE id=OLD.id; END'
        : 'CREATE TRIGGER reject_epoch BEFORE UPDATE ON user_auth FOR EACH ROW SET NEW.reset_perms=OLD.reset_perms');
} elseif ($failure === 'mutation') {
    $db->exec($driver === 'sqlite'
        ? "CREATE TRIGGER reject_mutation BEFORE DELETE ON user_auth_perms BEGIN SELECT RAISE(ABORT,'native mutation rejection'); END"
        : "CREATE TRIGGER reject_mutation BEFORE DELETE ON user_auth_perms FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='native mutation rejection'");
} elseif ($failure === 'myisam') {
    $db->exec('ALTER TABLE user_auth ENGINE=MyISAM');
} elseif ($failure === 'shadow') {
    $db->exec('CREATE TEMPORARY TABLE user_auth (id INTEGER PRIMARY KEY, reset_perms INT UNSIGNED NOT NULL DEFAULT 0, policy_graphs INTEGER NOT NULL DEFAULT 2) ENGINE=InnoDB');
    $db->exec('INSERT INTO user_auth VALUES (42,7,2)');
}
if ($scenario['caller'] ?? false) {
    $db->beginTransaction();
    $db->exec('INSERT INTO caller_work VALUES (99)');
}
$group = $scenario['group'] ?? false;
$kind = $scenario['kind'] ?? 'typed';
$sql = $group ? 'DELETE FROM user_auth_group_perms WHERE group_id = ? AND item_id = ? AND type = ?' : 'DELETE FROM user_auth_perms WHERE user_id = ? AND item_id = ? AND type = ?';
$parameters = array(42,100,1);
$member = null;
if ($kind === 'membership') {
    $sql = $group ? 'DELETE FROM user_auth_group_members WHERE group_id = ? AND user_id = ?' : 'DELETE FROM user_auth_group_members WHERE user_id = ? AND group_id = ?';
    $parameters = array(42,42);
    $member = $group ? 42 : null;
} elseif ($kind === 'policy') {
    $sql = 'UPDATE ' . ($group ? 'user_auth_group' : 'user_auth') . ' SET policy_graphs = ? WHERE id = ?';
    $parameters = array(1,42);
}
if ($scenario['absent'] ?? false) {
    $parameters[1] = 999;
    if ($group && $kind === 'membership') {
        $member = 999;
    }
}
$error = null;
$startQueries = $db->calls;
$startIndex = count($db->queries);
try {
    if ($kind === 'batch' || $kind === 'mixed') {
        $request = array('id' => 42,'associate_graph' => 1,'drp_action' => 2);
        $_POST = array();
        if ($kind === 'batch') {
            $count = $scenario['size'];
            $q = $db->prepare('REPLACE INTO ' . ($group ? 'user_auth_group_perms' : 'user_auth_perms') . ' VALUES (42,?,1)');
            for ($i = 100; $i < 100 + $count; $i++) {
                $q->execute(array($i));
                $_POST['chk_' . $i] = 'on';
            }
        } else {
            foreach ($scenario['selected'] as $item) {
                $_POST['chk_' . $item] = 'on';
            }
        }
        $startQueries = $db->calls;
        $startIndex = count($db->queries);
        $result = Kadupul\IdentityAccess\Infrastructure\Legacy\PermissionAssociations::apply($group);
    } else {
        $result = Kadupul\IdentityAccess\Infrastructure\Legacy\PermissionMutation::write($sql, $parameters, $group, 42, $member);
    }
} catch (Throwable $caught) {
    $result = false;
    $error = get_class($caught);
}
$queryCount = $db->calls - $startQueries;
$mutationQueries = array_slice($db->queries, $startIndex);
$state = array('result' => $result,'error' => $error,'transaction_open' => $db->inTransaction(),'epochs' => $db->query('SELECT id,reset_perms FROM user_auth ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'permissions' => $db->query('SELECT * FROM ' . ($group ? 'user_auth_group_perms' : 'user_auth_perms') . ' ORDER BY 1,2,3')->fetchAll(PDO::FETCH_ASSOC),'memberships' => $db->query('SELECT * FROM user_auth_group_members ORDER BY 1,2')->fetchAll(PDO::FETCH_ASSOC),'policy' => (int) $db->query('SELECT policy_graphs FROM ' . ($group ? 'user_auth_group' : 'user_auth') . ' WHERE id=42')->fetchColumn(),'messages' => $messages,'mutation_queries' => $queryCount,'first_mutation_queries' => array_slice($mutationQueries, 0, 18));
$state['initial_session'] = $initialSession;
$state['session'] = $_SESSION;
if ($scenario['caller'] ?? false) {
    $state['caller_work'] = (int) $db->query('SELECT COUNT(*) FROM caller_work')->fetchColumn();
    $db->rollBack();
    $state['rollback_permissions'] = (int) $db->query('SELECT COUNT(*) FROM user_auth_perms WHERE user_id=42')->fetchColumn();
    $state['rollback_caller_work'] = (int) $db->query('SELECT COUNT(*) FROM caller_work')->fetchColumn();
}
$GLOBALS['nativeChildCoverageMarkers'] = array('mutation-and-epochs-readback','transaction-ownership-readback');
fwrite(STDOUT, json_encode($state, JSON_THROW_ON_ERROR));
