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
require $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
if (($argv[3] ?? '') === 'coverage') {
    $sources = array('composer.lock', 'tests/composer.lock', 'tests/Symfony/MembershipEpochTransactionTest.php', 'cacti.sql', 'lib/auth.php', 'lib/functions.php', 'tests/Helpers/PhpSource.php', 'lib/database.php', 'user_group_admin.php', 'include/global_constants.php', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php');
    $GLOBALS['nativeChildCoverageSnapshot'] = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/membership-epoch-native.php', $scenario, $sources);
    define('MEMBERSHIP_EPOCH_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
require $root . '/include/global_constants.php';
require $root . '/lib/database.php';
require $root . '/lib/auth.php';
require $root . '/tests/Helpers/PhpSource.php';
eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'clean_up_lines'));

class MembershipEpochStatement extends PDOStatement
{
    private bool $readCompleted = false;
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        $this->readCompleted = true;
        return $row;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        $this->readCompleted = true;
        return $rows;
    }
    public function errorCode(): ?string
    {
        $owner = $GLOBALS['db'];
        if ($owner->fault === 'sqlstate' && str_starts_with($this->queryString, 'UPDATE user_auth SET reset_perms = CASE')) {
            $owner->faultValue = (int) $owner->query('SELECT reset_perms FROM user_auth WHERE id=42')->fetchColumn();
            return 'HY000';
        }
        if (str_starts_with($owner->fault, 'write-')) {
            $matches = array('write-member-add' => 'REPLACE INTO user_auth_group_members', 'write-member-delete' => 'DELETE FROM user_auth_group_members', 'write-child-delete' => 'DELETE FROM user_auth_group_realm', 'write-parent-delete' => 'DELETE FROM user_auth_group WHERE');
            if (str_starts_with($this->queryString, $matches[$owner->fault])) {
                $owner->faultValue = (int) $owner->query('SELECT COUNT(*) FROM user_auth_group_members WHERE group_id=1 AND user_id=42')->fetchColumn();
                return 'HY000';
            }
        }
        if ($owner->fault === 'metadata-select' && str_starts_with($this->queryString, 'SELECT * FROM `')) {
            return 'HY000';
        }
        if ($owner->fault === 'metadata-show' && str_starts_with($this->queryString, 'SHOW CREATE TABLE')) {
            return 'HY000';
        }
        if ($owner->fault === 'metadata-fetch' && $this->readCompleted && str_starts_with($this->queryString, 'SHOW CREATE TABLE')) {
            return 'HY000';
        }
        if ($owner->fault === 'read-group' && str_starts_with($this->queryString, 'SELECT id FROM user_auth_group WHERE')) {
            return 'HY000';
        }
        if ($owner->fault === 'read-user' && str_starts_with($this->queryString, 'SELECT id FROM user_auth WHERE')) {
            return 'HY000';
        }
        if ($owner->fault === 'read-snapshot' && (str_starts_with($this->queryString, 'SELECT group_id FROM user_auth_group_members') || str_starts_with($this->queryString, 'SELECT user_id FROM user_auth_group_members'))) {
            return 'HY000';
        }
        if ($owner->fault === 'read-late' && $this->readCompleted && str_starts_with($this->queryString, 'SELECT id FROM user_auth_group WHERE')) {
            return 'HY000';
        }
        return parent::errorCode();
    }
    public function errorInfo(): array
    {
        if (str_starts_with($GLOBALS['db']->fault, 'write-')) {
            return parent::errorInfo();
        }
        return $this->errorCode() === 'HY000' ? array('HY000', 5000, 'fixture unconfirmed read') : parent::errorInfo();
    }
}
class MembershipEpochPdo extends PDO
{
    public string $fault = '';
    public int $epochUpdates = 0;
    public ?int $faultValue = null;
    public ?PDO $swap = null;
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $query = str_replace(' FOR UPDATE', '', $query);
        }
        if (str_starts_with($query, 'UPDATE user_auth SET reset_perms = CASE')) {
            $this->epochUpdates++;
            if ($this->fault === 'prepare') {
                return false;
            }
        }
        if ($this->swap !== null && str_contains($query, 'FROM user_auth WHERE id = ?')) {
            $GLOBALS['database_sessions']['fixture:0:auth'] = $this->swap;
            $this->swap = null;
        }
        return parent::prepare($query, $options);
    }
    public function commit(): bool
    {
        if ($this->fault === 'commit') {
            return false;
        }
        return parent::commit();
    }
}
$db = new MembershipEpochPdo(getenv('KADUPUL_MEMBERSHIP_TEST_DSN') ?: 'sqlite::memory:', getenv('KADUPUL_MEMBERSHIP_TEST_USER') ?: null, getenv('KADUPUL_MEMBERSHIP_TEST_PASSWORD') ?: null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT, PDO::ATTR_STATEMENT_CLASS => array(MembershipEpochStatement::class)));
$mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$engine = $mysql ? ' ENGINE=InnoDB' : '';
$database_hostname = 'fixture';
$database_port = 0;
$database_default = 'auth';
$database_sessions = array('fixture:0:auth' => $db);
$config = array();
$_SESSION = array('sess_user_id' => 42, 'sess_user_realms' => array(99));
function cacti_count($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function kill_session_var($name)
{
    unset($_SESSION[$name]);
}
function cacti_log(...$arguments) {}
function cacti_debug_backtrace(...$arguments) {}
function cacti_require_post_actions($actions) {}
function isset_request_var($name)
{
    return false;
}
function set_default_action() {}
function get_request_var($name)
{
    return 'native-test';
}
function api_plugin_hook_function($name, $value = null)
{
    return true;
}
function __($value, ...$arguments)
{
    return vsprintf($value, $arguments);
}
if (!$mysql) {
    $db->sqliteCreateFunction('RAND', static fn() => 0.5);
    $db->sqliteCreateFunction('FLOOR', static fn($value) => floor($value));
}
$tables = array('user_auth', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm', 'user_auth_group_perms', 'caller_work');
try {
    $db->exec('CREATE TABLE user_auth(id INTEGER PRIMARY KEY, reset_perms ' . ($mysql ? 'INT UNSIGNED' : 'INTEGER') . ' NOT NULL)' . $engine);
    $db->exec('CREATE TABLE user_auth_group(id INTEGER PRIMARY KEY)' . $engine);
    $db->exec('CREATE TABLE user_auth_group_members(group_id INTEGER,user_id INTEGER,PRIMARY KEY(group_id,user_id))' . $engine);
    $db->exec('CREATE TABLE user_auth_group_realm(group_id INTEGER,realm_id INTEGER)' . $engine);
    $db->exec('CREATE TABLE user_auth_group_perms(group_id INTEGER,item_id INTEGER,type INTEGER)' . $engine);
    $db->exec('CREATE TABLE caller_work(id INTEGER PRIMARY KEY)' . $engine);
    $db->exec('INSERT INTO user_auth VALUES(7,13),(42,' . (str_contains($scenario, 'wrap') ? '4294967295' : '7') . '),(43,0),(44,99)');
    $db->exec('INSERT INTO user_auth_group VALUES(1),(2),(3)');
    $db->exec('INSERT INTO user_auth_group_members VALUES(1,42),(1,43),(1,999),(2,7),(3,44)');
    $db->exec('INSERT INTO user_auth_group_realm VALUES(1,8)');
    $db->exec('INSERT INTO user_auth_group_perms VALUES(1,9,1)');
    if (str_contains($scenario, 'absent-group')) {
        $db->exec('DELETE FROM user_auth_group WHERE id=' . (str_starts_with($scenario, 'remove') ? '1' : '2'));
    }
    if (str_contains($scenario, 'empty') || str_contains($scenario, 'orphan')) {
        $db->exec('DELETE FROM user_auth_group_members WHERE group_id=1');
        if (str_contains($scenario, 'orphan')) {
            $db->exec('INSERT INTO user_auth_group_members VALUES(1,999)');
        }
    }
    if (str_contains($scenario, 'later')) {
        $rows = array();
        $members = array();
        for ($id = 100; $id <= 1101; $id++) {
            $rows[] = '(' . $id . ',7)';
            if ($id !== 999) {
                $members[] = '(1,' . $id . ')';
            }
        }
        if ($db->exec('INSERT INTO user_auth VALUES ' . implode(',', $rows)) === false || $db->exec('INSERT INTO user_auth_group_members VALUES ' . implode(',', $members)) === false) {
            throw new RuntimeException('Later-batch fixture rows were not persisted');
        }
    }
    mkdir($directory . '/include', 0700);
    file_put_contents($directory . '/include/auth.php', '<?php');
    chdir($directory);
    require $root . '/user_group_admin.php';
    $fault = str_contains($scenario, 'denied') || str_contains($scenario, 'retry') ? 'denied' : (str_contains($scenario, 'mismatch') ? 'mismatch' : '');
    if ($fault !== '') {
        if ($mysql) {
            $body = $fault === 'denied' ? "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture epoch denial'" : 'SET NEW.reset_perms=OLD.reset_perms';
            if (str_contains($scenario, 'retry')) {
                $db->exec('SET @fixture_deny_epoch=1');
                $body = 'BEGIN IF @fixture_deny_epoch=1 THEN ' . $body . '; END IF; END';
            }
            if (str_contains($scenario, 'later')) {
                $body = 'BEGIN IF NEW.id=1101 THEN ' . $body . '; END IF; END';
            }
            $db->exec('CREATE TRIGGER fixture_epoch BEFORE UPDATE ON user_auth FOR EACH ROW ' . $body);
        } else {
            $condition = str_contains($scenario, 'later') ? ' WHEN NEW.id=1101' : '';
            $db->exec($fault === 'denied' ? "CREATE TRIGGER fixture_epoch BEFORE UPDATE OF reset_perms ON user_auth$condition BEGIN SELECT RAISE(FAIL, 'fixture epoch denial'); END" : 'CREATE TRIGGER fixture_epoch AFTER UPDATE OF reset_perms ON user_auth BEGIN UPDATE user_auth SET reset_perms=OLD.reset_perms WHERE id=NEW.id; END');
        }
    }
    foreach (array('prepare', 'sqlstate', 'commit', 'metadata-select', 'metadata-show', 'metadata-fetch', 'read-group', 'read-user', 'read-snapshot', 'read-late', 'write-member-add', 'write-member-delete', 'write-child-delete', 'write-parent-delete') as $control) {
        if (str_contains($scenario, $control)) {
            $db->fault = $control;
        }
    }
    if ($mysql && str_contains($scenario, 'myisam')) {
        $db->exec('ALTER TABLE user_auth_group_members ENGINE=MyISAM');
    }
    if ($mysql && str_contains($scenario, 'temporary')) {
        if ($db->exec('CREATE TEMPORARY TABLE user_auth_group_members(group_id INTEGER,user_id INTEGER,PRIMARY KEY(group_id,user_id)) ENGINE=InnoDB') === false) {
            throw new RuntimeException('Temporary shadow fixture was not created');
        }
        $shadowDdl = $db->query('SHOW CREATE TABLE user_auth_group_members')->fetch(PDO::FETCH_NUM)[1];
        if (stripos($shadowDdl, 'CREATE TEMPORARY TABLE') === false) {
            throw new RuntimeException('Temporary shadow fixture was not observed');
        }
    }
    if (str_contains($scenario, 'pool-swap')) {
        $db->swap = new PDO('sqlite::memory:');
    }
    $nested = str_contains($scenario, 'caller');
    if ($nested) {
        $db->beginTransaction();
        $db->exec('INSERT INTO caller_work VALUES(77)');
    }
    $status = 'complete';
    try {
        if (str_starts_with($scenario, 'remove')) {
            user_group_remove(1);
        } else {
            user_group_replace_memberships(42, 7);
        }
    } catch (Throwable $error) {
        $status = 'refused';
    }
    if ($mysql && str_contains($scenario, 'temporary')) {
        $db->exec('DROP TEMPORARY TABLE user_auth_group_members');
    }
    $read = static fn() => array('groups' => array_map('intval', $db->query('SELECT group_id FROM user_auth_group_members WHERE user_id=42 ORDER BY group_id')->fetchAll(PDO::FETCH_COLUMN)), 'parent' => (int) $db->query('SELECT COUNT(*) FROM user_auth_group WHERE id=1')->fetchColumn(), 'epochs' => $db->query('SELECT id, reset_perms FROM user_auth ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR), 'caller' => (int) $db->query('SELECT COUNT(*) FROM caller_work')->fetchColumn());
    $state = $read();
    $state['status'] = $status;
    $state['transaction'] = $db->inTransaction();
    $state['cache_cleared'] = !isset($_SESSION['sess_user_realms']);
    $state['epoch_updates'] = $db->epochUpdates;
    $state['fault_value'] = $db->faultValue;
    $state['pool_changed'] = $database_sessions['fixture:0:auth'] !== $db;
    if (str_contains($scenario, 'retry')) {
        if ($mysql) {
            $db->exec('SET @fixture_deny_epoch=0');
        } else {
            $db->exec('DROP TRIGGER fixture_epoch');
        }
        user_group_replace_memberships(42, 7);
        $state['retry'] = $read();
    }
    if ($nested) {
        $db->rollBack();
        $state['after_rollback'] = $read();
    }
    $state['driver'] = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $state['version'] = $mysql ? $db->query('SELECT VERSION()')->fetchColumn() : $db->query('SELECT sqlite_version()')->fetchColumn();
    $GLOBALS['nativeChildCoverageMarkers'] = array('persisted-membership-epochs-observed');
    print json_encode($state, JSON_THROW_ON_ERROR);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $db->fault = '';
    if ($mysql && str_contains($scenario, 'temporary')) {
        $db->exec('DROP TEMPORARY TABLE IF EXISTS user_auth_group_members');
    }
    foreach (array_reverse($tables) as $table) {
        $db->exec('DROP TABLE IF EXISTS ' . $table);
    }
    if (is_file($directory . '/include/auth.php')) {
        unlink($directory . '/include/auth.php');
        rmdir($directory . '/include');
    }
}
